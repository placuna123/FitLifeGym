<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class GymInfoController extends Controller
{
    private const DEFAULT_HOURS = [
        'Monday' => '9AM-5PM', 'Tuesday' => '9AM-5PM', 'Wednesday' => '9AM-5PM',
        'Thursday' => '9AM-5PM', 'Friday' => '9AM-5PM', 'Saturday' => '9AM-5PM', 'Sunday' => '9AM-5PM',
    ];

    public function index()
    {
        $gym = $this->currentGym();

        if (! $gym) {
            return response()->json([
                'success' => true,
                'gym' => [
                    'name' => 'FitGym',
                    'email' => '',
                    'phone' => '',
                    'gcash_number' => '',
                    'gcash_account_name' => '',
                    'gcash_qr_url' => null,
                    'address' => '',
                    'operating_hours' => self::DEFAULT_HOURS,
                    'textbee_device_id' => '',
                    'textbee_sim_subscription_id' => '',
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'gym' => $this->toPayload($gym),
        ]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'gcash_number' => 'nullable|string|max:20',
            'gcash_account_name' => 'nullable|string|max:255',
            'gcash_qr' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'address' => 'nullable|string',
            'operating_hours' => 'nullable|array',
            'textbee_device_id' => 'nullable|string|max:255',
            'textbee_sim_subscription_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $gym = $this->currentGym();
        if (! $gym) {
            return response()->json([
                'success' => false,
                'message' => 'No gym found for this account.'
            ], 404);
        }

        try {
            $gcashQrPath = $gym->gcash_qr_path;
            if ($request->hasFile('gcash_qr')) {
                $gcashQrPath = $request->file('gcash_qr')->store('gcash-qr', 'public');
            }

            $gym->update([
                'name' => $request->name,
                'email' => $request->email ?? '',
                'phone' => $request->phone ?? '',
                'gcash_number' => $request->gcash_number ?? '',
                'gcash_account_name' => $request->gcash_account_name ?? '',
                'gcash_qr_path' => $gcashQrPath,
                'address' => $request->address ?? '',
                'operating_hours' => $request->operating_hours ?? [],
                'textbee_device_id' => $request->textbee_device_id ?: null,
                'textbee_sim_subscription_id' => $request->textbee_sim_subscription_id ?: null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Gym information updated successfully',
                'gym' => $this->toPayload($gym->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update gym information: ' . $e->getMessage()
            ], 500);
        }
    }

    private function currentGym(): ?Gym
    {
        $gymId = Tenancy::gymId();

        return $gymId ? Gym::find($gymId) : null;
    }

    private function toPayload(Gym $gym): array
    {
        return [
            'id' => $gym->id,
            'name' => $gym->name,
            'slug' => $gym->slug,
            'email' => $gym->email,
            'phone' => $gym->phone,
            'gcash_number' => $gym->gcash_number ?? '',
            'gcash_account_name' => $gym->gcash_account_name ?? '',
            'gcash_qr_url' => ! empty($gym->gcash_qr_path) ? Storage::url($gym->gcash_qr_path) : null,
            'address' => $gym->address,
            'operating_hours' => $gym->operating_hours ?: self::DEFAULT_HOURS,
            'textbee_device_id' => $gym->textbee_device_id ?? '',
            'textbee_sim_subscription_id' => $gym->textbee_sim_subscription_id ?? '',
        ];
    }
}
