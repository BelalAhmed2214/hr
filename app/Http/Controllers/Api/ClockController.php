<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClockInOutRequest;
use App\Http\Requests\UpdateClockInOutRequest;
use App\Http\Resources\ClockResource;
use App\Models\ClockInOut;
// use App\Models\Request;
use App\Models\User;
use App\Traits\HelperTrait;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ClockController extends Controller
{
    use ResponseTrait;
    use HelperTrait;

    public function getUserClocksById(User $user)
    {
        $authUser = Auth::user();
        if (!$authUser->hasRole('Hr')) {
            return $this->returnError('You are not authorized to view users', 403);
        }
        $clocks = ClockInOut::where('user_id', $user->id)
            ->orderBy('clock_in', 'desc')
            ->paginate(7);

        if ($clocks->isEmpty()) {
            return $this->returnError('No Clocks For this user found');
        }

        $groupedClocks = $clocks->groupBy(function ($clock) {
            return Carbon::parse($clock->clock_in)->toDateString();
        });

        $data = [];

        foreach ($groupedClocks as $date => $clocksForDay) {
            $firstClockForDay = $clocksForDay->last();

            $otherClocksForDay = $clocksForDay->slice(1)->map(function ($clock) {
                return [
                    'clockIn' => Carbon::parse($clock->clock_in)->format('h:iA'),
                    'clockOut' => $clock->clock_out ? Carbon::parse($clock->clock_out)->format('h:iA') : null,
                ];
            });

            $data[] = (new ClockResource($firstClockForDay))->toArray(request()) + ['otherClocks' => $otherClocksForDay->values()->toArray()];
        }

        return $this->returnData("data", [
            'clocks' => $data,
            'pagination' => [
                'current_page' => $clocks->currentPage(),
                'next_page_url' => $clocks->nextPageUrl(),
                'previous_page_url' => $clocks->previousPageUrl(),
                'last_page' => $clocks->lastPage(),
                'total' => $clocks->total(),
            ],
        ], "Clocks Data for {$user->name}");
    }

    public function clockIn(StoreClockInOutRequest $request)
    {
        $authUser = Auth::user();

        $this->validate($request, [
            'latitude' => 'required',
            'longitude' => 'required',
        ]);

        $user_id = $authUser->id;
        $latitude = $request->latitude;
        $longitude = $request->longitude;

        $userLocations = $authUser->user_locations()->get();
        $closestLocation = null;
        $shortestDistance = null;

        foreach ($userLocations as $userLocation) {
            $location_id = $userLocation->pivot['location_id'];
            $userLongitude = $userLocation->longitude;
            $userLatitude = $userLocation->latitude;

            $distance = $this->haversineDistance($userLatitude, $userLongitude, $latitude, $longitude);

            if (is_null($shortestDistance) || $distance < $shortestDistance) {
                $shortestDistance = $distance;
                $closestLocation = [
                    'location_id' => $location_id,
                    'distance' => $distance,
                ];
            }
        }

        if (is_null($closestLocation)) {
            return $this->returnError('User is not located at any registered locations.');
        }

        $location_id = $closestLocation['location_id'];
        $distanceBetweenUserAndLocation = $closestLocation['distance'];
        $now = Carbon::now()->addRealHour(3);
        $UserEndTime = Carbon::createFromTimeString($authUser->user_detail->end_time);

        if ($now->greaterThan($UserEndTime)) {
            return $this->returnError('Your shift has already ended, you cannot clock in.');
        }
        $existingClockIn = ClockInOut::where('user_id', $user_id)
            ->where('location_id', $location_id)
            ->whereDate('clock_in', Carbon::today())
            ->whereNull('clock_out')
            ->orderBy('clock_in', 'desc')
            ->exists();
        if ($existingClockIn) {
            return $this->returnError('You have already clocked in.');
        }

        if ($distanceBetweenUserAndLocation < 50) {
            $clock = ClockInOut::create([
                'clock_in' => Carbon::now()->addRealHour(3),
                'clock_out' => null,
                'duration' => null,
                'user_id' => $user_id,
                'location_id' => $location_id,
            ]);

            return $this->returnData("clock", $clock, "Clock In Done");
        } else {
            return $this->returnError('User is not located at the correct location. lat : ' . $latitude . " / long : " . $longitude);
        }
    }

    public function clockOut(UpdateClockInOutRequest $request)
    {
        $this->validate($request, [
            'latitude' => 'required',
            'longitude' => 'required',
        ]);

        $latitude = $request->latitude;
        $longitude = $request->longitude;

        $authUser = Auth::user();

        $ClockauthUser = ClockInOut::where('user_id', $authUser->id)
            ->whereNull('clock_out')
            ->orderBy('clock_in', 'desc')
            ->first();

        if (!$ClockauthUser) {
            return $this->returnError('You are not clocked in.');
        }

        $user_location = $authUser->user_locations()->wherePivot('location_id', $ClockauthUser->location_id)->first();

        if (!$user_location) {
            return $this->returnError('User is not located at the correct location.');
        }

        $userLongitude = $user_location->longitude;
        $userLatitude = $user_location->latitude;

        $distanceBetweenUserAndLocation = $this->haversineDistance($userLatitude, $userLongitude, $latitude, $longitude);

        if ($distanceBetweenUserAndLocation < 50) {
            $clock_in = Carbon::parse($ClockauthUser->clock_in);
            $clock_out = Carbon::now()->addRealHour(3);
            $durationInterval = $clock_out->diffAsCarbonInterval($clock_in);
            $durationFormatted = $durationInterval->format('%H:%I:%S');
            $ClockauthUser->update([
                'clock_out' => $clock_out,
                'duration' => $durationFormatted,
            ]);

            return $this->returnData("clock", $ClockauthUser, "Clock Out Done");
        } else {
            return $this->returnError('User is not located at the correct location. lat : ' . $latitude . " / long : " . $longitude);
        }
    }

    public function showUserClocks()
    {
        $authUser = Auth::user();

        // Fetch all clocks for today
        $clocksToday = ClockInOut::where('user_id', $authUser->id)
            ->whereDate('clock_in', Carbon::today())
            ->orderBy('clock_in', 'asc')
            ->paginate(7);

        // Fetch all clocks for the user, regardless of the date
        $allClocksForUser = ClockInOut::where('user_id', $authUser->id)
            ->orderBy('clock_in', 'asc')
            ->get();

        // if ($clocksToday->isEmpty()) {
        //     return $this->returnError('No Clocks For this user Today');
        // }
        if ($allClocksForUser->isEmpty()) {
            return $this->returnError('No Clocks For this user ');
        }
        $groupedClocks = $clocksToday->groupBy(function ($clock) {
            return Carbon::parse($clock->clock_in)->toDateString();
        });

        $data = [];

        foreach ($groupedClocks as $date => $clocksForDay) {
            $firstClockForDay = $clocksForDay->first();

            $totalDuration = Carbon::createFromTime(0, 0, 0);

            foreach ($clocksForDay as $clock) {
                if ($clock->duration) {
                    $duration = Carbon::parse($clock->duration);
                    $totalDuration->addHours($duration->hour)
                        ->addMinutes($duration->minute)
                        ->addSeconds($duration->second);
                }
            }

            $totalDurationFormatted = $totalDuration->format('H:i:s');
            $firstClockForDay->duration = $totalDurationFormatted;

            $otherClocksForDay = $clocksForDay->slice(1)->map(function ($clock) {
                return [
                    'id' => $clock->id,
                    'clockIn' => Carbon::parse($clock->clock_in)->format('h:iA'),
                    'clockOut' => $clock->clock_out ? Carbon::parse($clock->clock_out)->format('h:iA') : null,
                ];
            });

            $data[] = (new ClockResource($firstClockForDay))->toArray(request()) + [
                'otherClocks' => $otherClocksForDay->values()->toArray(),
                'totalHours' => $totalDurationFormatted, // Include the total duration in the response
            ];
        }

        return $this->returnData("data", [
            'clocksToday' => $data,
            'allClocksForUser' => ClockResource::collection($allClocksForUser), // Include all clocks for the user
            'pagination' => [
                'current_page' => $clocksToday->currentPage(),
                'next_page_url' => $clocksToday->nextPageUrl(),
                'previous_page_url' => $clocksToday->previousPageUrl(),
                'last_page' => $clocksToday->lastPage(),
                'total' => $clocksToday->total(),
            ],
        ], "Clocks Data for {$authUser->name}");
    }

    public function updateUserClock(Request $request, User $user, ClockInOut $clock)
    {
        $authUser = Auth::user();
        if (!$authUser->hasRole('Hr')) {
            return $this->returnError('You are not authorized to update users', 403);
        }

        $this->validate($request, [
            'clock_in' => ['nullable', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i'],
        ]);

        $clock = ClockInOut::where('user_id', $user->id)->where('id', $clock->id)->first();
        if (!$clock) {
            return $this->returnError("No clocks found for this user", 404);
        }

        // $currentDate = Carbon::now()->format('Y-m-d');
        $existingDate = Carbon::parse($clock->clock_in)->format('Y-m-d');

        $clockIn = $request->clock_in ? Carbon::createFromFormat('Y-m-d H:i:s', "{$existingDate} {$request->clock_in}:00") : Carbon::parse($clock->clock_in);
        $clockOut = $request->clock_out ? Carbon::createFromFormat('Y-m-d H:i:s', "{$existingDate} {$request->clock_out}:00") : Carbon::parse($clock->clock_out);
        if ($clockOut->isSameDay($clockIn) === false) {
            return $this->returnError("clock_in and clock_out must be on the same day", 400);
        }

        $durationFormatted = $clockIn->diff($clockOut)->format('%H:%I:%S');

        $clock->update([
            'clock_in' => $clockIn->format('Y-m-d H:i:s'),
            'clock_out' => $clockOut->format('Y-m-d H:i:s'),
            'duration' => $durationFormatted,
        ]);

        return $this->returnData("clock", new ClockResource($clock), "Clock data for {$user->name}");
    }

}
