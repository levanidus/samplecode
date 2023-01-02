<?php

namespace App\Http\Api\Controllers;

use App\Events\Task\CreateTaskProcessed;
use App\Events\Task\EditTaskProcessed;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskRequest;
use App\Http\Resources\History\TaskHistoryResource;
use App\Http\Resources\Task\TaskDetailedResource;
use App\Http\Resources\Task\TaskResourceCollection;
use App\Http\Resources\Task\Assigned\ContractorResourceCollection as ContractorAssigned;
use App\Http\Resources\Task\Invitations\ContractorResourceCollection as ContractorInvitations;
use App\Http\Resources\Task\Responses\ContractorResourceCollection as ContractorResponses;
use App\Http\Resources\Task\SearchContractor\ContractorResourceCollection as SearchContractor;
use App\Http\Resources\Task\Selection\ContractorResourceCollection as ContractorSelection;
use App\Models\History\TaskHistory;
use App\Models\Object\ObjectModel;
use App\Models\Object\ObjectWork;
use App\Models\Task\Task;
use App\Models\Task\TaskWork;
use App\Models\Users\User;
use App\Services\Geo\GeoService;
use App\Services\Object\ObjectService;
use App\Services\User\ContractorService;
use App\Traits\Cte\DataRank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\LogAndGetErrorMessage\LogAndGetErrorMessage;
use App\Jobs\Object\ObjectUpdated;
use Carbon\Carbon;

use Symfony\Component\HttpFoundation\Response;

use function response;

/**
 * @group Заявки
 */
class TaskController extends Controller
{
    use DataRank, LogAndGetErrorMessage;

    /**
     * GET api/v1/tasks Список заявок
     *
     * Список заявок ( в зависимости от роли будут меняться ключи, сейчас установлены для менеджера )
     *
     * @apiResourceCollection App\Http\Resources\Dispatcher\TaskResource
     * @apiResourceModel App\Models\Task\Task
     */
    public function index(Request $request, String $user_role = null)
    {
        $user   = Config::get('user');
        $role   = $user_role ?? $user->type;

        $rate = ObjectService::getCurrentRate('vacancy');

        $objectWork = ObjectWork::select('id',
            DB::raw('last_value(rate) over(partition by object_rates.rateable_id order by start_date asc
            RANGE BETWEEN unbounded preceding and unbounded following) as rate'))
            ->leftJoinSub($rate, 'object_rates', 'object_works.id', 'object_rates.rateable_id');

        $taskWork = DB::table('task_works', 'task_works')
            ->select(
                'task_works.task_id',
                'object_work_id',
                'rate',
                DB::raw('coalesce(completed, 0) as completed'),
                DB::raw('(sum(requires_people)) as total'),
                DB::raw('(100 * completed / (sum(requires_people))) as percent'))
            ->joinSub($objectWork, 'object_works', 'task_works.object_work_id', 'object_works.id')
            ->leftJoin('task_contractors', 'task_contractors.task_id', '=', 'task_works.task_id')
            ->groupBy(['task_works.task_id', 'object_work_id', 'rate', 'completed']);

        $tasks = (new Task())
            ->appendQuery()
            ->setWithCte(
                'task_contractors',
                "select count(*) as completed, task_id
                from task_contractors
                where status in ('accepted', 'working')
                group by task_id"
            );

        if ($role == 'dispatcher') {
            $tasks = $tasks->setHas('dispatchers', 'user_id', [$user->id]);
        }

        if (!empty($request->filters) && is_array($request->filters)) {
            $request->filters = array_map(function ($element) {
                return json_decode($element);
            }, $request->filters);
        }

        $sort = 'tasks.created_at';
        if ($request->sort === 'object') { // Костыль чтобы обойти ограничения vuetify v-data-table
            $sort = 'object_name';
        } elseif ($request->sort === 'completion') {
            $sort = 'total';
        }

        $filters = $request->filters ?? [];
        foreach ($filters as $filter) {
            if ($filter->field === 'object') {
                $filter->field = 'object_uuid';
                break;
            }
        }

        $objects = DB::table('objects', 'objects')
            ->select(
                'objects.id as object_id',
                'objects.uuid as object_uuid',
                'objects.name as object_name'
            );

        $tasks = $tasks
            ->setLeftJoinSub($taskWork, 'task_works', 'tasks.id', 'task_works.task_id')
            ->setLeftJoinSub($objects, 'objects', 'tasks.object_id', 'objects.object_id')
            ->setFilters($filters)
            ->setMultipleSearch($request->search ?? null, ['name', 'description', 'object_name'])
            ->closeQuery($sort, $request->order ?? null, $request->per_page ?? 50);

        return new TaskResourceCollection($tasks);
    }

    /**
     * PUT api/v1/tasks/{uuid} Создание заявки
     *
     * Создание заявки
     *
     * @bodyParam account string uuid клиента.
     * @bodyParam name string Наименование заявки.
     * @bodyParam object datetime uuid специализации.
     * @bodyParam description string описание.
     * @bodyParam start_date string начало заявки.
     * @bodyParam end_date string конец заявки.
     * @bodyParam until_date string начало работ по заявке.
     * @bodyParam region string Регион.
     * @bodyParam city string Город.
     * @bodyParam scheme string Схема.
     * @bodyParam dispatchers array Массив uuid диспетчеров.
     * @bodyParam contacts array Массив контактов.
     * @bodyParam works array Массив профессий из справочника (объекта).
     */
    public function create(TaskRequest $request)
    {
        $user = Config::get('user');
        $object = ObjectModel::where('uuid', $request->object)->first();

        $task = new Task();
        $task->fill(array_merge($request->all(), [
                'object_id'         => $object->id,
                'specialization_id' => $object->specialization_id,
                'status'            => Task::CREATED,
                'until_date'        => $request->end_date,
                'city'              => $object->city,
                'author_id' => $user['id'],
            ]));

        if (empty($request->shift)) {
            $startDate = Carbon::parse(Carbon::parse($request->start_date)->format('Y-m-d 00:00:00'));
            $endDate = Carbon::parse(Carbon::parse($request->end_date)->format('Y-m-d 00:00:00'));
            if ($startDate->diffInDays($endDate) == 0) {
                $task->shift = 'Дневная';
            }
            else {
                $task->shift = 'Ночная';
            }
        }    

        $task->system_name = $this->getTaskSystemName($request,$task);

        $task->save();

        $task->setContacts($object->objectContact()->get()->toArray());

        if ($object->address > '') {
            $address = GeoService::getCoordinates($object->address);

            if ($address && $address->isSuccess()) {
                $task->update([
                    'lat'    => $address->lat,
                    'lon'    => $address->lon,
                    'scheme' => $request->scheme ?? $address->address,
                    'region' => $address->region ?? $request->region,
                ]);
            }
        }

        if ($request->works && !empty($request->works[0]['uuid'])) {
            $objectWork = ObjectWork::where('uuid', $request->works[0]['uuid'])->first();
            if (!is_null($objectWork)) {
                $taskWork = new TaskWork();
                $taskWork->task_id = $task->id;
                $taskWork->requires_people = (int)$request->works[0]['requires_people'];
                $taskWork->object_work_id = $objectWork->id;
                $taskWork->save();
            }
        }

        event(new CreateTaskProcessed($task));

        ObjectUpdated::dispatch($object)
            ->onQueue(env('OBJECT_UPDATED_QUEUE_NAME', 'object_updated'))
            ->delay(now()->addMinutes(env('OBJECT_UPDATED_QUEUE_DELAY', 5)));

        return ['uuid' => $task->uuid];
    }

    /**
     * POST api/v1/task/{uuid} копирование заявки
     *
     * Копирование заявки
     */
    public function copy(Task $task, Request $request)
    {
        try {

            DB::beginTransaction();

            $name = self::taskName($task->name);

            $copy       = $task->replicate();
            $copy->name = $name;
            $copy->save();

            $copy->setDispatchers($task->dispatchers()->pluck('uuid')->toArray() ?? []);
            $copy->copyWorks(DB::table('task_works')->where('task_id', $task->id)->get() ?? []);
            $copy->setContacts(($task->taskContact)->toArray() ?? []);

            DB::commit();

            return [$copy->uuid];

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => $this->logAndGetErrorMessage($request, $e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private static function taskName(String $name)
    {
        $task = Task::where('name', 'ILIKE', '%' . $name . '%')->orderBy('id', 'desc')->value('name');

        $last_word = explode(' ', $task);
        $last_word = array_pop($last_word);

        $num = substr($task, -1);

        if (is_numeric($num)) {
            return substr_replace($task, ' ', -2) . $num + 1;
        } elseif ($last_word == 'копия') {
            return $task . ' 2';
        } else {
            return $task . ' копия';
        }
    }

    /**
     * PUT api/v1/tasks/{uuid} Обновление заявки
     *
     * Обновление заявки
     *
     * @bodyParam account string uuid клиента.
     * @bodyParam name string Наименование заявки.
     * @bodyParam specialization datetime uuid специализации.
     * @bodyParam description string описание.
     * @bodyParam start_date string начало заявки.
     * @bodyParam end_date string конец заявки.
     * @bodyParam until_date string начало работ по заявке.
     * @bodyParam region string Регион.
     * @bodyParam city string Город.
     * @bodyParam scheme string Схема.
     * @bodyParam dispatchers array Массив uuid диспетчеров.
     * @bodyParam contacts array Массив контактов.
     * @bodyParam works array Массив профессий из справочника (объекта).
     */
    public function update(TaskRequest $request, Task $task)
    {
        $data = $request->all();

        if ($task->object->uuid != $request->object) {
            return response()->json([
                'message' => 'Change object for task is prohibited'
            ], Response::HTTP_BAD_REQUEST);
        }

        $task->fill($data);

        if (empty($request->shift)) {
            $startDate = Carbon::parse(Carbon::parse($request->start_date)->format('Y-m-d 00:00:00'));
            $endDate = Carbon::parse(Carbon::parse($request->end_date)->format('Y-m-d 00:00:00'));
            if ($startDate->diffInDays($endDate) == 0) {
                $task->shift = 'Дневная';
            }
            else {
                $task->shift = 'Ночная';
            }
        }    

        $task->system_name = $this->getTaskSystemName($request, $task);
        if (!empty($data['works'])) {
            $task->setWorks($data['works']);
        }
        $task->save();

        event(new EditTaskProcessed($task));

        ObjectUpdated::dispatch($task->object)
            ->onQueue(env('OBJECT_UPDATED_QUEUE_NAME', 'object_updated'))
            ->delay(now()->addMinutes(env('OBJECT_UPDATED_QUEUE_DELAY', 5)));

        return ['uuid' => $task->uuid];
    }

    public function works(Task $task, Request $request)
    {
        try {

            $task->setWorks($request->all())->save();

            return new JsonResponse([
                'data' => 'set work success',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $this->logAndGetErrorMessage($request, $e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function contacts(Task $task, Request $request)
    {
        try {

            $task->setContacts($request->all() ?? []);

            return new JsonResponse([
                'data' => 'set contacts success',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $this->logAndGetErrorMessage($request, $e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function dispatchers(Task $task, Request $request)
    {
        try {

            $task->setDispatchers($request->all() ?? []);

            return new JsonResponse([
                'data' => 'set dispatchers success',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $this->logAndGetErrorMessage($request, $e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET api/v1/tasks/{uuid} Детализация заявки
     *
     * Детализация заявки ( в зависимости от роли будут меняться ключи, сейчас установлены для менеджера )
     *
     * @apiResource App\Http\Resources\Dispatcher\TaskDetailedResource
     * @apiResourceModel App\Models\Task\Task
     */
    public function get(Task $task)
    {
        $task->view !== null ? $task->increment('view', 1) : $task->update(['view' => 1]);
        return new TaskDetailedResource($task);
    }

    /**
     * DELETE api/v1/task/{uuid} удаление заявки
     *
     * Удаление заявки
     */
    public function delete(Request $request)
    {
        // event(new DeleteTaskProcessed($task));

        $tasks = Task::whereIn('uuid', $request->all())->delete();

        return ['Delete success'];
    }

    /**
     * Отклики на заявку
     *
     * GET api/v1/tasks/{uuid}/responses
     *
     * @apiResourceCollection App\Http\Resources\Task\Responses\ContractorResourceCollection
     * @apiResourceModel App\Models\Users\User
     */
    public function responses(Task $task, Request $request)
    {
        $payload = $this->condition($task->id, 'accepted.count');

        $params = json_decode($request->settings);

        $contractors = ContractorService::listInTask(
            $task->id,
            $task->object_id,
            $payload,
            json_decode($request->professions) ?? [],
            [User::REQUESTED, User::REJECTED],
        );

        $contractors = (new User)
            ->setWrapSelect($contractors, 'contractors')
            ->setFilters($params->filters ?? [])
            ->setSearch($params->search ?? null, $params->value ?? null)
            ->setMultipleSearch($params->value ?? null, [
                'lastname',
                'firstname',
                'middlename',
                'phone',
                'rate',
                'trust',
                'age',
                'rank',
                'on_object',
            ])
            ->closeQuery($params->sort ?? null, $params->order ?? null, $request->per_page ?? 15);

        return new ContractorResponses($contractors);
    }

    /**
     * GET api/v1/tasks/{uuid}/selection подбор
     *
     * Подбор
     *
     * @apiResourceCollection App\Http\Resources\Manager\ContractorResource
     * @apiResourceModel App\Models\Users\User
     */
    public function selection(Task $task, Request $request)
    {
        $payload = $this->condition($task->id, 'accepted.count');

        $params = json_decode($request->settings);

        $contractors = ContractorService::listInTask(
            $task->id,
            $task->object_id,
            $payload,
            json_decode($request->professions) ?? [],
            [User::LOCAL],
        );

        $contractors = (new User)
            ->setWrapSelect($contractors, 'contractors')
            ->setFilters($params->filters ?? [])
            ->setSearch($params->search ?? null, $params->value ?? null)
            ->setMultipleSearch($params->value ?? null, [
                'lastname',
                'firstname',
                'middlename',
                'phone',
                'rate',
                'trust',
                'age',
                'rank',
                'on_object',
            ])
            ->closeQuery($params->sort ?? null, $params->order ?? null, $request->per_page ?? 15);

        return new ContractorSelection($contractors);
    }

    /**
     * GET api/v1/tasks/{uuid}/invitations приглашенные
     *
     * Приглашенные
     *
     * @apiResourceCollection App\Http\Resources\Manager\ContractorResource
     * @apiResourceModel App\Models\Users\User
     */
    public function invitations(Task $task, Request $request)
    {
        $payload = $this->condition($task->id, 'accepted.count');

        $params = json_decode($request->settings);

        $contractors = ContractorService::listInTask(
            $task->id,
            $task->object_id,
            $payload,
            json_decode($request->professions) ?? [],
            [User::INVITED, User::ACCEPT_INVITED],
        );

        $contractors = (new User)
            ->setWrapSelect($contractors, 'contractors')
            ->setFilters($params->filters ?? [])
            ->setSearch($params->search ?? null, $params->value ?? null)
            ->setMultipleSearch($params->value ?? null, [
                'lastname',
                'firstname',
                'middlename',
                'phone',
                'rate',
                'trust',
                'age',
                'rank',
                'on_object',
            ])
            ->closeQuery($params->sort ?? null, $params->order ?? null, $request->per_page ?? 15);

        return new ContractorInvitations($contractors);
    }

    /**
     * GET api/v1/tasks/{uuid}/assigned назначенные
     *
     * Назначенные
     *
     * @apiResourceCollection App\Http\Resources\Manager\ContractorResource
     * @apiResourceModel App\Models\Users\User
     */
    public function assigned(Task $task, Request $request)
    {
        $payload = $this->condition($task->id, 'accepted.count');

        $params = json_decode($request->settings);

        $contractors = ContractorService::listInTask(
            $task->id,
            $task->object_id,
            $payload,
            json_decode($request->professions) ?? [],
            [User::ACCEPTED, User::WORKING, User::REFUSED],
        );

        $contractors = (new User)
            ->setWrapSelect($contractors, 'contractors')
            ->setFilters($params->filters ?? [])
            ->setSearch($params->search ?? null, $params->value ?? null)
            ->setMultipleSearch($params->value ?? null, [
                'lastname',
                'firstname',
                'middlename',
                'phone',
                'rate',
                'trust',
                'age',
                'rank',
                'on_object',
            ])
            ->closeQuery($params->sort ?? null, $params->order ?? null, $request->per_page ?? 15);

        return new ContractorAssigned($contractors);
    }

    public function search(Task $task, Request $request)
    {
        $params = json_decode($request->settings);

        if ($request->region) {
            $geo = GeoService::getCoordinates($request->region);
        }

        $coordinates = [
            'region' => $geo->region ?? null,
            'lat'    => $task->lat, //$geo->lat ?? $task->lat,
            'lon'    => $task->lon, //$geo->lon ?? $task->lon,
            'radius' => $request->radius ?? 0,
        ];

        /** Условия выполнения для отображения рангов */
        $payload = $this->condition($task->id, 'accepted.count');

        /** Список исполнителей */
        $contractors = ContractorService::listInTask(
            $task->id,
            $task->object_id,
            $payload,
            json_decode($request->professions) ?? [],
            null,
            (object) $coordinates
        );

        /** Оборачиваем в новый select и прикручиваем фильтры */
        $filterContractors = (new User)
            ->setWrapSelect($contractors, 'contractors')
            ->setSelectAfterJoin(
                DB::raw('*'),
                DB::raw('row_number() over (partition by id) as second')
            )
            ->setFilters($params->filters ?? [])
            ->setSearch($params->search ?? null, $params->value ?? null)
            ->setMultipleSearch($params->value ?? null, [
                'lastname',
                'firstname',
                'middlename',
                // 'phone',
                // 'rate',
                // 'trust',
                // 'age',
                // 'rank',
                // 'on_object',
                // 'address',
            ])->getQuery();

        /** Оборачиваем в еще 1 select для уникального списка */
        $distinctContractors = (new User)
            ->setWrapSelect($filterContractors, 'contractors')
            ->setFilter('second', 1)
            ->closeQuery($params->sort ?? null, $params->order ?? null, $request->per_page ?? 15);

        return new SearchContractor($distinctContractors);
    }

    public function history(string $task_uuid)
    {
        $task = Task::select('id')->first(); // find($task_uuid);
        Log::info($task);
        if (!empty($task)) {
            $histories = TaskHistory::where('task_id', $task->id)->get();
        } else {
            $histories = [];
        }

        return TaskHistoryResource::collection($histories);
    }

    /**
     * PUT api/v1/task/{uuid}/{status} обновление статуса
     *
     * Обновление статуса
     */
    public function status(Task $task, string $status)
    {
        $task->update([
            'status' => $status,
        ]);

        event(new EditTaskProcessed($task));
        return ['Status update success.'];
    }

    public function getTaskSystemName($request,$task) {
        $taskObjectWork = $task->taskWork()->first();
        return Carbon::parse($request->start_date)->format('d.m')
                                . (!is_null($taskObjectWork) ? ' ' . $taskObjectWork->name : '')
                                . ' ' . $task->object->code;
    }
}
