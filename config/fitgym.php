<?php

return [

    /*
    | Length of the free trial a newly signed-up gym branch gets.
    */
    'trial_days' => 14,

    /*
    | FitGym subscription plans for gym branches. `members` and `staff` cap the
    | number of active (non-archived) records a branch may have; null = unlimited.
    | `price` is what a branch pays FitGym per month (PHP); the Super Admin can
    | still type a different amount when recording a payment.
    */
    'plans' => [
        'trial' => ['label' => 'Trial', 'members' => 50, 'staff' => 3, 'price' => 0],
        'basic' => ['label' => 'Basic', 'members' => 200, 'staff' => 10, 'price' => 999],
        'pro' => ['label' => 'Pro', 'members' => null, 'staff' => null, 'price' => 1999],
    ],

];
