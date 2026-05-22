<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
	use WithoutModelEvents;

	/**
	 * Seed the application's database.
	 */
	public function run(): void
	{
		// User::factory(10)->create();

		User::factory()->create([
			'name'     		=> 'Super Admin',
			'username' 		=> 'admin_mik',
			'email'    		=> 'mjmaun.dev@gmail.com',
			'role'    		=> 'super_admin',
			'password'    	=> '$2y$12$ai6QKLzcrknJt46.me6GIOG13m5Bf//tQBUvo0SCAgwEV/15At3O6',
			'password_confirmation'    => '$2y$12$ai6QKLzcrknJt46.me6GIOG13m5Bf//tQBUvo0SCAgwEV/15At3O6	',
		]);
	}
}
