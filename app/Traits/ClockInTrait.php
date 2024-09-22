<?php

namespace App\Traits;

use App\Http\Requests\Api\AddClockRequest;
use App\Models\ClockInOut;
use App\Models\User;
use App\Traits\ClockTrait;
use Illuminate\Support\Carbon;

trait ClockInTrait
{
    use ClockTrait;
    protected function checkClockInWithoutClockOut($user_id)
    {
        $query = ClockInOut::where('user_id', $user_id)
            ->whereNull('clock_out')
            ->exists();

        return $query;
    }
    
    protected function validateLocations($request, $authUser)
    {
        // Retrieve location details using location_id from the request
        $location_id = $request->location_id;

        // Validate that the location_id is assigned to the user
        $userLocation = $this->getUserAssignedLocationById($authUser, $location_id);
        if (!$userLocation) {
            return null;
        }

        // Validate latitude and longitude comparison with the assigned location
        $latitude = $request->latitude;
        $longitude = $request->longitude;

        $distance = $this->haversineDistance($latitude, $longitude, $userLocation->latitude, $userLocation->longitude);

        // Check if user is within an acceptable range (e.g., 50 meters)
        if ($distance > 50) {
            return $this->returnError('User is not located at the correct location. lat: ' . $latitude . ' / long: ' . $longitude);
        }

        // Return the validated location
        return $userLocation;
    }
    protected function createClockInHomeRecord($request, $user_id, $clockIn, $late_arrive)
    {
        $clock = ClockInOut::create([
            'clock_in' => $clockIn,
            'clock_out' => null,
            'duration' => null,
            'user_id' => $user_id,
            'location_type' => $request->location_type,
            'late_arrive' => $late_arrive,
            'early_leave' => null,
        ]);

        return $this->returnData("clock", $clock, "Clock In Done");
    }
    protected function handleHomeClockIn($request, $user_id)
    {
        //1- Calculate Late_arrive
        $authUser = User::findOrFail($user_id);
        $clockIn = Carbon::parse($request->clock_in);
        $userStartTime = Carbon::parse($authUser->user_detail->start_time);
        $late_arrive = $this->calculateLateArrive($clockIn, $userStartTime);

        // Call createHomeClockIn method to create the clock-in record
        return $this->createClockInHomeRecord($request, $user_id, $clockIn, $late_arrive);
    }

    protected function handleSiteClockIn($request, $authUser)
    {
        //1- Validate location of user and location of the site
        $userLocation = $this->validateLocations($request, $authUser);
        if ($userLocation == null) {
            return $this->returnError('Location is not assigned to the user.');
        }
        //2- check the department_name for authenticated user
        $clockIn = Carbon::parse($request->clock_in);

        if (($authUser->department->name == "Academic_school") || ($authUser->department->name == "Factory")) {

            //Calculate Late_arrive by location time
            $locationStartTime = Carbon::parse($userLocation->start_time);
            $late_arrive = $this->calculateLateArrive($clockIn, $locationStartTime);
        } else {
            //Calculate Late_arrive by User time
            $userStartTime = carbon::parse($authUser->user_detail->start_time);
            $late_arrive = $this->calculateLateArrive($clockIn, $userStartTime);
        }
        //3- create ClockIn Site Record
        return $this->createClockInSiteRecord($request, $authUser, $userLocation, $clockIn, $late_arrive);
    }

    protected function createClockInSiteRecord($request, $authUser, $userLocation, $clockIn, $late_arrive)
    {
        $clock = ClockInOut::create([
            'clock_in' => $clockIn,
            'clock_out' => null,
            'duration' => null,
            'user_id' => $authUser->id,
            'location_id' => $request->location_id,
            'location_type' => $request->location_type,
            'late_arrive' => $late_arrive,
            'early_leave' => null,
        ]);

        return $this->returnData("clock", $clock, "Clock In Done");
    }

    //HR Clock
    protected function handleSiteClockInByHr(AddClockRequest $request, User $user)
    {

        //1- Validate that the location_id is assigned to the user
        $location_id = $request->location_id;

        $userLocation = $user->user_locations()->where('location_id', $location_id)->first();

        if (!$userLocation) {
            return $this->returnError('The specified location is not assigned to the user.');
        }

        //2- Calculate the late_arrive
        $clockIn = Carbon::parse($request->clock_in);
        if ($user->department->name == "Academic_school" || $user->department->name == "Factory") {
            //Calculate Late_arrive by location_time
            $locationStartTime = Carbon::parse($userLocation->start_time);
            $late_arrive = $this->calculateLateArrive($clockIn, $locationStartTime);
        } else {
            //Calculate Late_arrive by User time
            $userStartTime = carbon::parse($user->user_detail->start_time);
            $late_arrive = $this->calculateLateArrive($clockIn, $userStartTime);
        }

        // Create the clock-in record
        return $this->createClockInSiteRecord($request, $user, $userLocation, $clockIn, $late_arrive);
    }
}