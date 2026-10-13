<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Lets any signed-in account (Admin, Staff, Member, Super Admin) upload or remove
 * its own profile photo. Photos live on the public disk under profile-photos/.
 */
class ProfilePhotoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ], [
            'photo.required' => 'Choose a photo to upload.',
            'photo.image' => 'The file must be a photo (JPG, PNG or WebP).',
            'photo.mimes' => 'The file must be a photo (JPG, PNG or WebP).',
            'photo.max' => 'The photo must be 4 MB or smaller.',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $user = $request->user();
        $old = $user->profile_photo_path;
        $user->profile_photo_path = $request->file('photo')->store('profile-photos', 'public');
        $user->save();

        if ($old && $old !== $user->profile_photo_path) {
            Storage::disk('public')->delete($old);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile photo updated.',
            'photo_url' => $user->profilePhotoUrl(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->profile_photo_path = null;
            $user->save();
        }

        return response()->json(['success' => true, 'message' => 'Profile photo removed.', 'photo_url' => null]);
    }
}
