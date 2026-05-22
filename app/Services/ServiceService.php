<?php

namespace App\Services;

use App\Models\Service;

class ServiceService
{
	public function list($filters = [])
	{
		$query = Service::with('category');

		if (isset($filters['branch_id'])) {
			$query->where('branch_id', $filters['branch_id']);
		}

		if (isset($filters['active'])) {
			$query->where('is_active', $filters['active']);
		}

		return $query->latest()->get();
	}

	public function create(array $data): Service
	{
		return Service::create($data);
	}

	public function update(Service $service, array $data): Service
	{
		$service->update($data);
		return $service;
	}

	public function delete(Service $service): void
	{
		$service->delete();
	}

	public function toggle(Service $service): Service
	{
		$service->is_active = !$service->is_active;
		$service->save();

		return $service;
	}
}