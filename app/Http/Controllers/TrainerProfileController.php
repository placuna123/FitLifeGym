<?php

namespace App\Http\Controllers;

use App\Models\Trainer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TrainerProfileController extends Controller
{
    /**
     * Get current trainer profile (from Trainer record linked to Auth user email)
     */
    public function show()
    {
        if (! Auth::check()) {
            return response()->json(['success' => false, 'message' => 'Not authenticated'], 401);
        }
        $user = Auth::user();
        $trainer = Trainer::where('email', $user->email)->where('is_archived', 0)->first();
        if (! $trainer) {
            return response()->json([
                'success' => true,
                'trainer' => [
                    'id' => null,
                    'full_name' => $user->name,
                    'email' => $user->email,
                    'phone' => '',
                    'position' => '',
                    'role' => $user->role ?? 'Staff',
                ]
            ]);
        }
        return response()->json([
            'success' => true,
            'trainer' => [
                'id' => $trainer->id,
                'full_name' => $trainer->full_name,
                'email' => $trainer->email,
                'phone' => $trainer->phone ?? '',
                'position' => $trainer->position ?? '',
                'role' => $user->role ?? 'Staff',
            ]
        ]);
    }

    /**
     * Update trainer profile (User name + Trainer record)
     */
    public function update(Request $request)
    {
        if (! Auth::check()) {
            return response()->json(['success' => false, 'message' => 'Not authenticated'], 401);
        }

        $user = Auth::user();
        $trainer = Trainer::where('email', $user->email)->where('is_archived', 0)->first();

        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user->id),
                Rule::unique('trainers', 'email')->ignore($trainer?->id),
            ],
            'phone' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update([
            'name' => $request->full_name,
            'email' => $request->email,
        ]);

        if ($trainer) {
            $trainer->update([
                'full_name' => $request->full_name,
                'email' => $request->email,
                'phone' => $request->phone ?? $trainer->phone,
            ]);
        }
        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'trainer' => [
                'full_name' => $request->full_name,
                'email' => $request->email,
                'phone' => $request->phone ?? '',
            ]
        ]);
    }

    /**
     * Upload profile photo
     */
    public function uploadPhoto(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            if ($request->hasFile('photo')) {
                // Store new photo
                $path = $request->file('photo')->store('trainer/photos', 'public');
                $photoUrl = '/storage/' . $path;

                return response()->json([
                    'success' => true,
                    'message' => 'Photo uploaded successfully',
                    'photo_url' => $photoUrl
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No photo file provided'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload photo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change password (updates User password)
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|min:8',
            'new_password_confirmation' => 'required|same:new_password',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        if (! Auth::check()) {
            return response()->json(['success' => false, 'message' => 'Not authenticated'], 401);
        }
        $user = Auth::user();
        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'Current password is incorrect'], 422);
        }
        $user->update(['password' => Hash::make($request->new_password)]);
        \App\Support\Email::passwordChanged($user);
        return response()->json(['success' => true, 'message' => 'Password changed successfully']);
    }
}
