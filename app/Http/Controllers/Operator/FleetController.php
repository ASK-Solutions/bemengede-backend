<?php

namespace App\Http\Controllers\Operator;

use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Terminal;
use App\Models\Trip;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetController extends OperatorScoped
{
    // ---------- Buses ----------
    public function buses(Request $request): JsonResponse
    {
        return response()->json(Bus::where('operator_id', $this->opId($request))->orderBy('plate_number')->get());
    }

    public function storeBus(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $bus = Bus::create(['operator_id' => $this->opId($request)] + $this->busData($request));
        AuditLog::record('bus.created', $bus);

        return response()->json($bus, 201);
    }

    public function updateBus(Request $request, Bus $bus): JsonResponse
    {
        $this->own($request, $bus);
        $bus->update($this->busData($request, true));
        AuditLog::record('bus.updated', $bus);

        return response()->json($bus);
    }

    /** Buses with future trips are retired instead of deleted. */
    public function destroyBus(Request $request, Bus $bus): JsonResponse
    {
        $this->own($request, $bus);
        $this->ownerOnly($request);
        if (Trip::where('bus_id', $bus->id)->where('departure_at', '>', now())->where('status', '!=', 'cancelled')->exists()) {
            abort(422, 'This bus has upcoming trips. Move them to another bus first.');
        }
        Trip::where('bus_id', $bus->id)->exists() ? $bus->update(['status' => 'retired']) : $bus->delete();
        AuditLog::record('bus.removed', $bus);

        return response()->json(['ok' => true]);
    }

    private function busData(Request $r, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $d = $r->validate([
            'plate_number' => [$req, 'string', 'max:20'],
            'side_number' => ['nullable', 'string', 'max:20'],
            'class' => [$req, 'in:standard,vip'],
            'seat_count' => [$req, 'integer', 'min:10', 'max:80'],
            'columns' => [$req, 'integer', 'in:3,4,5'],
            'aisle_after' => [$req, 'integer', 'min:1', 'max:4'],
            'blocked' => ['nullable', 'array'],
            'blocked.*' => ['integer', 'min:1'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['in:ac,wifi,usb,toilet,tv'],
            'status' => ['sometimes', 'in:active,maintenance,retired'],
        ]);
        if (isset($d['columns']) || isset($d['seat_count'])) {
            $cols = $d['columns'] ?? 4;
            $d['layout'] = ['rows' => (int) ceil(($d['seat_count'] ?? 51) / $cols), 'columns' => $cols, 'aisle_after' => $d['aisle_after'] ?? 2, 'blocked' => $d['blocked'] ?? []];
        }
        unset($d['columns'], $d['aisle_after'], $d['blocked']);

        return $d;
    }

    // ---------- Drivers ----------
    public function drivers(Request $request): JsonResponse
    {
        return response()->json(Driver::where('operator_id', $this->opId($request))->orderBy('full_name')->get());
    }

    public function storeDriver(Request $request): JsonResponse
    {
        $d = Driver::create(['operator_id' => $this->opId($request)] + $this->driverData($request));
        AuditLog::record('driver.created', $d);

        return response()->json($d, 201);
    }

    public function updateDriver(Request $request, Driver $driver): JsonResponse
    {
        $this->own($request, $driver);
        $driver->update($this->driverData($request, true));

        return response()->json($driver);
    }

    private function driverData(Request $r, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $d = $r->validate([
            'full_name' => [$req, 'string', 'max:80'],
            'phone' => [$req, 'string', Phone::rule()],
            'license_no' => [$req, 'string', 'max:40'],
            'license_expires_on' => [$req, 'date'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        if (isset($d['phone'])) {
            $d['phone'] = Phone::normalize($d['phone']);
        }

        return $d;
    }

    // ---------- Routes ----------
    public function terminals(): JsonResponse
    {
        return response()->json(Terminal::with('city:id,name_en')->orderBy('city_id')->get());
    }

    public function routes(Request $request): JsonResponse
    {
        return response()->json(Route::with('origin.city', 'destination.city')->where('operator_id', $this->opId($request))->get()
            ->map(fn (Route $r) => $r->toArray() + ['label' => $r->origin->city->name_en.' ('.$r->origin->name.') → '.$r->destination->city->name_en]));
    }

    public function storeRoute(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $d = $request->validate([
            'origin_terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'destination_terminal_id' => ['required', 'integer', 'exists:terminals,id', 'different:origin_terminal_id'],
            'distance_km' => ['required', 'integer', 'min:1', 'max:3000'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:3000'],
            'default_fare_etb' => ['nullable', 'numeric', 'min:1'],
            'stops' => ['nullable', 'array'],
            'stops.*' => ['string', 'max:40'],
        ]);
        $route = Route::create([
            'operator_id' => $this->opId($request),
            'default_fare' => isset($d['default_fare_etb']) ? (int) round($d['default_fare_etb'] * 100) : null,
        ] + collect($d)->except('default_fare_etb')->all());
        AuditLog::record('route.created', $route);

        return response()->json($route, 201);
    }
}
