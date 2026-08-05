<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use Illuminate\Database\Seeder;

class PrivateKeySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PrivateKey::create([
            'uuid' => 'ssh',
            'team_id' => 0,
            'name' => 'Testing Host Key',
            'description' => 'This is a test docker container',
            'private_key' => coolify_testing_openssh_private_key(),
        ]);

        PrivateKey::create([
            'uuid' => 'github-key',
            'team_id' => 0,
            'name' => 'development-github-app',
            'description' => 'This is the key for using the development GitHub app',
            'private_key' => coolify_testing_github_app_rsa_private_key(),
            'is_git_related' => true,
        ]);
    }
}
