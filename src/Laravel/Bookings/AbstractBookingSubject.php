<?php

namespace Shirahcan\CalendarClient\Laravel\Bookings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The defaults most products want (K6). A product sets `$model`, `$refColumn` and `$refPrefix`,
 * and implements enabled(), hosts(), occupies(), start(), end() and the attribute lists.
 */
abstract class AbstractBookingSubject implements BookingSubject
{
    /** @var class-string<Model> */
    protected string $model;

    /** The model's uuid column (exists before insert). */
    protected string $refColumn = 'uuid';

    /** e.g. 'booking' gives 'booking:{uuid}'. */
    protected string $refPrefix = 'booking';

    public function modelClass(): string
    {
        return $this->model;
    }

    public function column(): string
    {
        return 'calendar_booking_id';
    }

    public function ref(Model $m): string
    {
        $uuid = (string) $m->getAttribute($this->refColumn);
        if ($uuid === '') {
            throw new \LogicException(class_basename($m).' needs its '.$this->refColumn.' before it can be booked in calendar-service.');
        }

        return $this->refPrefix.':'.$uuid;
    }

    public function linkedByRef(string $ref): ?string
    {
        $prefix = $this->refPrefix.':';
        if (! str_starts_with($ref, $prefix)) {
            return null;
        }
        $query = ($this->model)::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($this->model), true)) {
            $query->withTrashed();
        }

        $id = $query->where($this->refColumn, substr($ref, strlen($prefix)))->value($this->column());

        return $id === null ? null : (string) $id;
    }

    public function describe(Model $m): ?array
    {
        return null;
    }

    public function approval(Model $m): ?string
    {
        return null;
    }

    public function mirrorRef(Model $m): ?string
    {
        return null;
    }

    public function externalEvents(Model $m): array
    {
        return [];
    }

    public function checkBusyOnJoin(): bool
    {
        return true;
    }

    public function actor(): string
    {
        return config('app.name', 'product').':booking';
    }
}
