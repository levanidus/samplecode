<?php

namespace App\Models\Task;

use App\Models\History\TaskHistory;
use App\Models\Notification\Notification;
use App\Models\Object\ObjectModel;
use App\Models\Object\ObjectWork;
use App\Models\Specialization\Specialization;
use App\Models\Task\TaskContact;
use App\Models\Task\TaskWork;
use App\Models\TimeSheet\TimeSheetItem;
use App\Models\TimeSheet\TimeSheetEntity;
use App\Models\Users\User;
use App\Traits\Cte\Cte;
use App\Traits\Filters\Filter;
use App\Traits\Model\generateUuid;
use App\Traits\Model\getRouteKeyName;
use App\Traits\Model\Relations;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Task extends Model
{
    use Filter, Relations, generateUuid, getRouteKeyName, Cte;
    use HasFactory;

    const CREATED               = 'created';
    const IS_RECRUTING          = 'isRecruiting';
    const RECRUITMENT_COMPLETED = 'recruitmentCompleted';
    const WORKING               = 'working';
    const WORKING_COMPLETED     = 'workingCompleted';
    const AGREEMENT             = 'agreement';
    const COMPLETED             = 'completed';
    // const CONFIRMATION          = 'confirmation';
    // const NOTCONFIRMED          = 'notConfirmed';
    // const CONFIRMED             = 'confirmed';

    private $status_task = [
        self::CREATED               => 'Создана',
        self::IS_RECRUTING          => 'Ведется набор',
        self::RECRUITMENT_COMPLETED => 'Набор завершен',
        self::WORKING               => 'Подтверждение выхода', //'В работе',
        self::WORKING_COMPLETED     => 'Работы завершены',
        self::AGREEMENT             => 'Согласование',
        self::COMPLETED             => 'Завершена',
        // self::CONFIRMATION          => 'Подтверждение',
        // self::NOTCONFIRMED          => 'Не подтверждена',
        // self::CONFIRMED            => 'Подтверждена',
    ];

    private $status_tasks_search_in_contractor = [
        self::IS_RECRUTING => 'Открыт набор',
    ];

    protected $fillable = [
        'author_id',
        'object_id',
        'category_id',
        'specialization_id', //TODO deprecated временно просто назначаем такой же как у объекта, чтобы не сломать связи
        'uuid',
        'name',
        'description',
        'region',
        'city',
        'scheme',
        'lat',
        'lon',
        'start_date',
        'end_date',
        'until_date',
        'status',
        'view',
        'payment',
        'hours',
        'shift',
    ];

    protected $dates = [
        'start_date',
        'end_date',
        'until_date',
    ];

    protected $hidden = [
        'id',
        'author_id',
        'object_id',
        'category_id',
        'specialization_id',
    ];

    protected static $sequenceableKeys = [
        'number',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ObjectModel::class);
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }

    public function taskWork(): BelongsToMany
    {
        return $this->belongsToMany(ObjectWork::class, 'task_works')
            ->withPivot('requires_people')
            ->withTimestamps();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(TaskContact::class);
    }

    public function taskContact(): HasMany
    {
        return $this->hasMany(TaskContact::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id', 'id');
    }

    public function dispatchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_dispatchers');
    }

    public function contractors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_contractors')
            ->withPivot('status', 'event', 'created_at', 'sms', 'telegram', 'email')
            ->withTimestamps();
    }

    public function timesheetitems(): HasMany
    {
        return $this->hasMany(TimeSheetItem::class);
    }

    public function timesheetentity(): HasOne
    {
        return $this->hasOne(TimeSheetEntity::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TaskHistory::class);
    }

    public function getCompletion()
    {
        $total = collect($this->taskWork)->map(function ($work) {
            return $work->requires_people;
        })->sum();

        $completed = $this->contractors()
            ->withPivot('status', 'accepted')
            ->count();

        return [
            'total'     => $total,
            'completed' => $completed,
        ];
    }

    public function setDispatchers(array $dispatchers)
    {
        if (count($dispatchers)) {

            if (!empty($this->dispatchers)) {
                $this->dispatchers()->detach();
            }

            $users = User::whereIn('uuid', $dispatchers)->pluck('id')->toArray();

            $this->dispatchers()->attach($users, [
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
        else
        {
            if (!empty($this->dispatchers)) {
                $this->dispatchers()->detach();
            }
        }
    }

    public function setContacts(array $contacts)
    {
        if (!empty($this->taskContact)) {
            $this->taskContact()->delete();
        }

        foreach ($contacts as $contact) {

            $this->taskContact()->create(array_merge($contact, [
                'uuid' => TaskContact::generateUuid(),
            ]));
        }

    }

    public function setWorks(array $works)
    {
        if (count($works)) {
            if (!empty($this->taskWork)) {
                $this->taskWork()->detach();
            }
            foreach ($works as $work) {
                $vacancy = ObjectWork::whereUuid($work['uuid'])->first();
                $this->taskWork()->attach($vacancy->id, [
                    'created_at'      => Carbon::now(),
                    'updated_at'      => Carbon::now(),
                    'requires_people' => $work['requires_people'],
                ]);
                $this->system_name = Carbon::parse($this->start_date)->format('d.m')
                                    . ' ' . $vacancy->name
                                    . ' ' . $this->object->code;
            }
        }
        return $this;
    }

    public function copyWorks(Collection $works)
    {
        foreach ($works as $work) {

            $this->taskWork()->attach($work->object_work_id, [
                'requires_people' => $work->requires_people,
            ]);
        }
    }

    private function getPayment(string $uuid)
    {
        $payment = ObjectWork::where('uuid', $uuid)->first();

        switch ($payment->period) {

            case 'за час':

                return (int) $payment->payment;

            case 'за смену':

                return (int) round($payment->payment / 8);
        }
    }

    // get distanse between $settings and task location
    public function scopeIsWithinMaxDistance($query, $settings)
    {

        $haversine = "(6371 * acos(cos(radians(" . $settings->location_lat . "))
                        * cos(radians(lat))
                        * cos(radians(lon)
                        - radians(" . $settings->location_lon . "))
                        + sin(radians(" . $settings->location_lat . "))
                        * sin(radians(lat))))";

        return $query->selectRaw("tasks.*, {$haversine} AS distance")
            ->whereRaw("{$haversine} < ?", [$settings->location_radius]);
    }
}
