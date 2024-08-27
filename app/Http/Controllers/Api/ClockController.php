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

    protected function prepareClockData($clocks)
    {
        $isPaginated = $clocks instanceof \Illuminate\Pagination\LengthAwarePaginator;

        // Group clocks by date
        $groupedClocks = $clocks->groupBy(function ($clock) {
            return Carbon::parse($clock->clock_in)->toDateString();
        });

        $data = [];

        foreach ($groupedClocks as $date => $clocksForDay) {
            // Sort clocks by clock_in time to get the earliest one first
            $clocksForDay = $clocksForDay->sortBy(function ($clock) {
                return Carbon::parse($clock->clock_in);
            });
            // Get the earliest clock (the first clock of the day)
            $firstClockAtTheDay = $clocksForDay->first();
            // dd($firstClockAtTheDay->toArray());

            // Initialize total duration in seconds
            $totalDurationInSeconds = 0;

            // Calculate total duration including all clocks for the day
            foreach ($clocksForDay as $clock) {
                if ($clock->clock_out && $clock->clock_in) {
                    $clockIn = Carbon::parse($clock->clock_in);
                    $clockOut = Carbon::parse($clock->clock_out);
                    $durationInSeconds = $clockOut->diffInSeconds($clockIn);

                    $totalDurationInSeconds += $durationInSeconds;

                }
            }
            // Convert total duration from seconds to H:i:s format
            $hours = floor($totalDurationInSeconds / 3600);
            $minutes = floor(($totalDurationInSeconds % 3600) / 60);

            $seconds = $totalDurationInSeconds % 60;

            $totalDurationFormatted = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
            // Update the total duration for the first clock entry
            $firstClockAtTheDay->duration = $totalDurationFormatted;

            // Prepare other clocks for the day, excluding the first one
            $otherClocksForDay = $clocksForDay->skip(1)->map(function ($clock) {
                return [
                    'id' => $clock->id,
                    'clockIn' => $clock->clock_in ? Carbon::parse($clock->clock_in)->format('h:iA') : null,
                    'clockOut' => $clock->clock_out ? Carbon::parse($clock->clock_out)->format('h:iA') : null,
                    'totalHours' => $clock->duration ? Carbon::parse($clock->duration)->format('h:i') : null,
                ];
            });

            // Prepare the final data structure for this date
            $data[] = (new ClockResource($firstClockAtTheDay))->toArray(request()) + [
                'otherClocks' => $otherClocksForDay->values()->toArray(),
                'totalHours' => $totalDurationFormatted,
            ];
        }

        return [
            'clocks' => $data,
            'pagination' => $isPaginated ? [
                'current_page' => $clocks->currentPage(),
                'next_page_url' => $clocks->nextPageUrl(),
                'previous_page_url' => $clocks->previousPageUrl(),
                'last_page' => $clocks->lastPage(),
                'total' => $clocks->total(),
            ] : null,
        ];
    }

    public function getUserClocksById(Request $request, User $user)
    {
        $authUser = Auth::user();
        if (!$authUser->hasRole('Hr')) {
            return $this->returnError('You are not authorized to view users', 403);
        }

        $query = ClockInOut::where('user_id', $user->id);

        if ($request->has('date')) {
            $date = Carbon::parse($request->get('date'))->toDateString();
            $query->whereDate('clock_in', $date);
            $clocks = $query->orderBy('clock_in', 'desc')->get();

        } else {
            $clocks = $query->orderBy('clock_in', 'desc')->paginate(7);

        }

        if ($clocks->isEmpty()) {
            return $this->returnError('No Clocks Found For This User');
        }

        $data = $this->prepareClockData($clocks);

        return $this->returnData("data", $data, "Clocks Data for {$user->name}");
    }

    public function clockIn(StoreClockInOutRequest $request)
    {
        $authUser = Auth::user();
        $user_id = $authUser->id;

        $this->validate($request, [
            'location_type' => 'required|string|exists:work_types,name',
            'clock_in' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);

        if ($request->location_type == "home") {
            $existingHomeClockIn = ClockInOut::where('user_id', $user_id)
                ->whereDate('clock_in', Carbon::today())
                ->where('location_type', "home")
                ->whereNull('clock_out')
                ->orderBy('clock_in', 'desc')
                ->exists();
            if ($existingHomeClockIn) {
                return $this->returnError('You have already clocked in.');
            }
            // $clockIn = Carbon::parse($request->clock_in);
            $clock = ClockInOut::create([
                'clock_in' => Carbon::parse($request->clock_in),
                'clock_out' => null,
                'duration' => null,
                'user_id' => $user_id,
                'location_id' => null,
                'location_type' => $request->location_type,
            ]);
            return $this->returnData("clock", $clock, "Clock In Done");
        }

        $this->validate($request, [
            'latitude' => 'required',
            'longitude' => 'required',
        ]);
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
        $UserEndTime = Carbon::parse($authUser->user_detail->end_time);

        $existingSiteClockIn = ClockInOut::where('user_id', $user_id)
            ->where('location_id', $location_id)
            ->whereDate('clock_in', Carbon::today())
            ->whereNull('clock_out')
            ->orderBy('clock_in', 'desc')
            ->exists();
        if ($existingSiteClockIn) {
            return $this->returnError('You have already clocked in.');
        }

        if ($distanceBetweenUserAndLocation < 50) {
            $clock = ClockInOut::create([
                'clock_in' => Carbon::parse($request->clock_in),
                'clock_out' => null,
                'duration' => null,
                'user_id' => $user_id,
                'location_id' => $location_id,
                'location_type' => $request->location_type,

            ]);

            return $this->returnData("clock", $clock, "Clock In Done");
        } else {
            return $this->returnError('User is not located at the correct location. lat : ' . $latitude . " / long : " . $longitude);
        }
    }

    public function clockOut(UpdateClockInOutRequest $request)
    {
        $authUser = Auth::user();

        $ClockauthUser = ClockInOut::where('user_id', $authUser->id)
            ->whereNull('clock_out')
            ->orderBy('clock_in', 'desc')
            ->first();
        $request->validate([
            'clock_out' => ['required', "date_format:Y-m-d H:i:s"],
        ]);
        if (!$ClockauthUser) {
            return $this->returnError('You are not clocked in.');
        }

        // if (Carbon::parse($ClockauthUser->clock_in)->greaterThan(Carbon::now()->addRealHour(3))) {
        //     return $this->returnError("You Can't clocked out now");
        // }
        $clockOut = Carbon::parse($request->clock_out);
        if ($clockOut < Carbon::parse($ClockauthUser->clock_in)) {
            return $this->returnError("You Can't clocked out now");
        }
        if ($ClockauthUser->location_type == "home") {
            $clock_in = Carbon::parse($ClockauthUser->clock_in);
            $clock_out = $clockOut;
            $durationInterval = $clock_out->diffAsCarbonInterval($clock_in);
            $durationFormatted = $durationInterval->format('%H:%I:%S');
            $ClockauthUser->update([
                'clock_out' => $clock_out,
                'duration' => $durationFormatted,
            ]);
            return $this->returnData("clock", $ClockauthUser, "Clock Out Done");

        }
        $user_location = $authUser->user_locations()->wherePivot('location_id', $ClockauthUser->location_id)->first();
        if (!$user_location) {
            return $this->returnError('User is not located at the correct location.');
        }

        $this->validate($request, [
            'latitude' => 'required',
            'longitude' => 'required',
        ]);
        $latitude = $request->latitude;
        $longitude = $request->longitude;

        $userLongitude = $user_location->longitude;
        $userLatitude = $user_location->latitude;

        $distanceBetweenUserAndLocation = $this->haversineDistance($userLatitude, $userLongitude, $latitude, $longitude);

        if ($distanceBetweenUserAndLocation < 50) {
            $clock_in = Carbon::parse($ClockauthUser->clock_in);
            $clock_out = $clockOut;
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

    public function showUserClocks(Request $request)
    {
        $authUser = Auth::user();

        $query = ClockInOut::where('user_id', $authUser->id);

        if ($request->has('date')) {
            $date = Carbon::parse($request->get('date'))->toDateString();
            $query->whereDate('clock_in', $date);
        }
        $clocks = $query->orderBy('clock_in', 'desc')->paginate(7);

        if ($clocks->isEmpty()) {
            return $this->returnError('No Clocks Found For This User');
        }

        $data = $this->prepareClockData($clocks);

        return $this->returnData("data", $data, "Clocks Data for {$authUser->name}");
    }

    public function updateUserClock(Request $request, User $user, ClockInOut $clock)
    {
        $authUser = Auth::user();
        if (!$authUser->hasRole('Hr')) {
            return $this->returnError('You are not authorized to update users', 403);
        }

        $this->validate($request, [
            'clock_in' => ['nullable', 'date_format:Y-m-d H:i'],
            'clock_out' => ['nullable', 'date_format:Y-m-d H:i'],
        ]);

        $clock = ClockInOut::where('user_id', $user->id)->where('id', $clock->id)->first();
        if (!$clock) {
            return $this->returnError("No clocks found for this user", 404);
        }

        $existingDate = Carbon::parse($clock->clock_in)->format('Y-m-d');

        // Adjust clock_in time if provided
        if ($request->clock_in) {
            $clockIn = Carbon::createFromFormat('Y-m-d H:i', $request->clock_in);
        } else {
            $clockIn = Carbon::parse($clock->clock_in);
        }

        // Adjust clock_out time if provided
        if ($request->clock_out) {
            $clockOut = Carbon::createFromFormat('Y-m-d H:i', $request->clock_out);
        } else {
            $clockOut = Carbon::parse($clock->clock_out);
        }

        // Check if clock_in and clock_out are on the same day
        if (!$clockOut->isSameDay($clockIn)) {
            return $this->returnError("clock_in and clock_out must be on the same day", 400);
        }

        // Ensure clock_in is before clock_out
        if ($clockOut->lessThanOrEqualTo($clockIn)) {
            return $this->returnError("clock_out must be after clock_in", 400);
        }

        // Calculate duration
        $durationFormatted = $clockIn->diff($clockOut)->format('%H:%I:%S');

        // Update clock record
        $clock->update([
            'clock_in' => $clockIn->format('Y-m-d H:i:s'),
            'clock_out' => $clockOut->format('Y-m-d H:i:s'),
            'duration' => $durationFormatted,
        ]);
        return $this->returnData("clock", new ClockResource($clock), "Clock data for {$user->name}");
    }

}