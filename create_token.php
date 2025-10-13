<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

$user = User::where('role', 'admin')->first();
if ($user) {
    $token = $user->createToken('test-token')->plainTextToken;
    echo "Token: " . $token . "\n";
    echo "User ID: " . $user->id . "\n";
    echo "User Name: " . $user->name . "\n";
} else {
    echo "No admin user found\n";
}
