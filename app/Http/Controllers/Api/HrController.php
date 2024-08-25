<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class HrController extends Controller
{
    use ResponseTrait;
    public function getLocationAssignedToUser()
    {

        $users = User::with('user_locations')->get();

        $data = [];

        foreach ($users as $user) {
            foreach ($user->user_locations as $location) {
                $pivotData = $location->pivot->toArray();
                $data[] = ['user_location' => $pivotData];
            }

            return $this->returnData('user_locations', $data, 'User Location Data');
        }
    }
    public function getWorkTypeAssignedToUser()
    {
        $users = User::with('work_types')->get();
        $data = [];
        foreach ($users as $user) {
            foreach ($user->work_types as $work_type) {
                $pivotData = $work_type->pivot->toArray();
                $data[] = ['user_work_type' => $pivotData];
            }
        }
        return $this->returnData('user_work_types', $data, 'User WorkType Data');
    }

    public function assignLocationToUser(Request $request, User $user)
    {
        // $auth = Auth::user();
        // if (!$auth->hasRole('Hr')) {
        //     return $this->returnError('User is unauthorized to assign location', 403);
        // }
        // $this->validate($request, [
        //     'location_id' => 'required|exists:locations,id',

        // ]);

        // $LocationAssignedToUser = $user->user_locations()->wherePivot('location_id', $request->location_id)->first();
        // if ($LocationAssignedToUser) {
        //     return $this->returnError('User has already been assigned to this location');
        // }
        // $user->user_locations()->attach($request->location_id);
        // return $this->returnSuccessMessage('Location Assigned Successfully To User');

    }
    public function assignWorkTypeToUser(Request $request, User $user)
    {
        // $this->validate($request, [
        //     'work_type_id' => 'required|exists:work_types,id',
        // ]);
        // $workTypeAssignedToUser = $user->work_types()->where('work_type_id', $request->work_type_id)->exists();
        // if ($workTypeAssignedToUser) {
        //     return $this->returnError('User has already been assigned to this workType');
        // }
        // $user->work_types()->attach($request->work_type_id);
        // return $this->returnSuccessMessage('WorkType Assigned Successfully to User');
    }
}