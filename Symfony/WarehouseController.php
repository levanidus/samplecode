<?php

namespace App\Controller;

use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Entity\User;
use App\Entity\WorkOrder;
use App\Entity\WarehouseConsumption;
use App\Entity\WarehouseConsumptionRemainders;
use App\Entity\WarehouseCorrection;
use App\Entity\DeleteHistory;
use App\EntityListener\WarehouseConsumptionListener;
use App\Repository\NomenclatureRepository;
use App\Repository\UserRepository;
use App\Repository\WarehouseRepository;
use App\Service\SoftDeleteTransactionService;
use App\Service\WarehouseService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;

/**
 * @Route("/api/warehouse")
 */
class WarehouseController extends AbstractController
{
    /**
     * @Route("/create", name="warehouse_create")
     * @param Request $request
     * @param EntityManagerInterface $em
     * @param NomenclatureRepository $nomenclatureRepository
     * @return Response
     * @throws Exception
     */
    public function create(Request $request, EntityManagerInterface $em, NomenclatureRepository $nomenclatureRepository)
    {
        $request_data = json_decode($request->getContent());
        $warehouse = new Warehouse();

        $nomenclature_id = $request_data->nomenclatue_positions->selected;
        $supplier_id = $request_data->client->selected;

        $nomenclature = $nomenclatureRepository->find($nomenclature_id);

        $warehouse->setNomenclature($nomenclature);
        $warehouse->setRemainderVolume($nomenclature->getQuantityInPack());
        $warehouse->setRemainderVolumeWithSquare($nomenclature->getQuantityInPack());

        $warehouse->setState($request_data->state->selected);
        if ($supplier_id)
            $warehouse->setSupplier($em->getRepository(Supplier::class)->findOneById($supplier_id));
        $warehouse->setOrderDate($request_data->order_date ? new DateTime($request_data->order_date) : null);
        $warehouse->setDeliveryDate($request_data->delivery_date ? new DateTime($request_data->delivery_date) : null);
        $warehouse->setAccountNumber($request_data->account_number);
        $warehouse->setPaymentDate($request_data->payment_date ? new DateTime($request_data->payment_date) : null);
        $warehouse->setDocumentsStatus($request_data->documents_status);
        $warehouse->setEntity($request_data->entity->selected);
        $warehouse->setLocation($request_data->location->selected);
        $warehouse->setLocker($request_data->locker);
        $warehouse->setShelf($request_data->shelf);
        $warehouse->setComment($request_data->comment);

        $warehouse->setPackingVolume($nomenclature->getQuantityInPack());
        $warehouse->setPurchasePrice($nomenclature->getUnitPrice());

        $em->persist($warehouse);

        $em->flush();

        return new Response('{"result":"success","type":"create","id":"' . $warehouse->getId() . '"}');
    }

    /**
     * @Route("/", name="warehouse_list", methods={"GET"})
     * @param WarehouseRepository $warehouseRepository
     * @return JsonResponse
     */
    public function list(Request $request, EntityManagerInterface $em)
    {
        $search = $request->query->get('search');
        $category = $request->query->get('category', []);
        $state = $request->query->get('state') ? explode(',', $request->query->get('state')) : '';
        $supplier = $request->query->get('supplier');
        $location = $request->query->get('location');
        $company = $request->query->get('company');
        $remainder_price_from = $request->query->get('remainder_price_from');
        $remainder_price_to = $request->query->get('remainder_price_to');
        $nomenclature_quantity_in_package_from = $request->query->get('nomenclature_quantity_in_package_from');
        $nomenclature_quantity_in_package_to = $request->query->get('nomenclature_quantity_in_package_to');
        $payment = $request->query->get('payment');
        $order_date_from = $request->query->get('order_date_from');
        $order_date_to = $request->query->get('order_date_to');
        $work_order_id = $request->query->get('work_order_id');
        $deleted = $request->query->get('deleted');

        if ($deleted) {
            if ($deleted == 'actual')
                $em->getFilters()->enable('softdeleteable');
            if ($deleted == 'all' || $deleted == 'deleted')
                $em->getFilters()->disable('softdeleteable');
        }
        else
            $em->getFilters()->enable('softdeleteable');

        $qb = $em->createQueryBuilder();
        $qb->select('w')
        ->from(Warehouse::class, 'w')
        ->leftJoin('w.nomenclature', 'n');

        $conditionsArr = [];

        if ($deleted && $deleted == 'deleted') {
            $conditionsArrEl = ['condition' => 'w.deleted_at is not null'];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($state != '') {
            $conditionsArrEl = ['condition' => 'w.state IN (:state)',
                                'field' => 'state',
                                'meaning' => $state];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($work_order_id) {
            $work_order = $em->getRepository(WorkOrder::class)
                             ->find($work_order_id);
            if (!empty($work_order)) {
                $conditionsArrEl = ['condition' => 'w.location = :location',
                                    'field' => 'location',
                                    'meaning' => $work_order->getLocation()];
                $conditionsArr[] = $conditionsArrEl;
            }
        }

        if (count($category)) {
            $conditionsArrEl = ['condition' => 'n.category IN (:category)',
                                'field' => 'category',
                                'meaning' => $category];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($supplier) {
            $conditionsArrEl = ['condition' => 'w.supplier = :supplier',
                                'field' => 'supplier',
                                'meaning' => $supplier];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($search) {
            $conditionsArrEl = ['condition' => 'n.name = :search',
                                'field' => 'search',
                                'meaning' => $search];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($location) {
            $conditionsArrEl = ['condition' => 'w.location = :location',
                                'field' => 'location',
                                'meaning' => $location];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($company) {
            $conditionsArrEl = ['condition' => 'w.entity = :company',
                                'field' => 'company',
                                'meaning' => $company];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($remainder_price_from)  {
            $conditionsArrEl = ['condition' => 'w.remainder_price >= :remainder_price_from',
                                'field' => 'remainder_price_from',
                                'meaning' => $remainder_price_from];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($remainder_price_to) {
            $conditionsArrEl = ['condition' => 'w.remainder_price <= :remainder_price_to',
                                'field' => 'remainder_price_to',
                                'meaning' => $remainder_price_to];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($nomenclature_quantity_in_package_from) {
            $conditionsArrEl = ['condition' => 'n.quantity_in_pack >= :nomenclature_quantity_in_package_from',
                                'field' => 'nomenclature_quantity_in_package_from',
                                'meaning' => $nomenclature_quantity_in_package_from];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($nomenclature_quantity_in_package_to) {
            $conditionsArrEl = ['condition' => 'n.quantity_in_pack <= :nomenclature_quantity_in_package_to',
                                'field' => 'nomenclature_quantity_in_package_to',
                                'meaning' => $nomenclature_quantity_in_package_to];
            $conditionsArr[] = $conditionsArrEl;
        }

        if ($payment && $payment != '') {
            if ($payment == 1)
                $conditionsArrEl = ['condition' => 'w.payment_date is not null'];
            if ($payment == 2)
                $conditionsArrEl = ['condition' => 'w.payment_date is null'];
            $conditionsArr[] = $conditionsArrEl;
        }

        if (isset($order_date_from)) {
            $conditionsArr[] = ['condition' => 'w.order_date >= :order_date_from',
                                'field' => 'order_date_from',
                                'meaning' => (new \DateTime($order_date_from))->format('Y-m-d 00:00:00')];
        }

        if (isset($order_date_to)) {
            $conditionsArr[] = ['condition' => 'w.order_date <= :order_date_to',
                                'field' => 'order_date_to',
                                'meaning' => (new \DateTime($order_date_to))->format('Y-m-d 23:59:59')];
        }

        foreach ($conditionsArr as $oneCondition) {
            $qb->andWhere($oneCondition['condition']);
            if (isset($oneCondition['field']))
                $qb->setParameter($oneCondition['field'], $oneCondition['meaning']);
        }

        $qb->orderBy('w.id', 'DESC');

        $warehouses = $qb->getQuery()->getResult();
        $result['warehouses'] = $this->queryToArray($warehouses);

        // COUNT STATS WITH CONDITIONS

        $qb = $em->createQueryBuilder();
        $qb->select('count(w.id) as items_count, sum(n.unit_price) as buying_price, sum(w.remainder_price) as reminder_price');
        $qb->from(Warehouse::class, 'w');
        $qb->leftJoin('w.nomenclature', 'n');
        foreach ($conditionsArr as $oneCondition) {
            $qb->andWhere($oneCondition['condition']);
            if (isset($oneCondition['field']))
                $qb->setParameter($oneCondition['field'], $oneCondition['meaning']);
        }
        $stat = $qb->getQuery()->getSingleResult(Query::HYDRATE_ARRAY);

        // считаем сальдо взаиморасчетов с поставщиками. оплачено, но не поставлено - поставлено, но не оплачено
        $qb = $em->createQueryBuilder();
        $qb->select('sum(n.unit_price)');
        $qb->from(Warehouse::class, 'w');
        $qb->leftJoin('w.nomenclature', 'n');
        $qb->where("w.state IN (:statuses)")
        ->setParameter('statuses', [Warehouse::STATUS_IN_WAREHOUSE, Warehouse::STATUS_PRODUCTION, Warehouse::STATUS_CLOSED])
        ->andWhere("w.payment_date IS NULL");
        foreach ($conditionsArr as $oneCondition) {
            $qb->andWhere($oneCondition['condition']);
            if (isset($oneCondition['field']))
                $qb->setParameter($oneCondition['field'], $oneCondition['meaning']);
        }
        $stoked_but_not_payed = $qb->getQuery()->getSingleScalarResult(Query::HYDRATE_ARRAY);

        $qb = $em->createQueryBuilder();
        $qb->select('sum(n.unit_price)');
        $qb->from(Warehouse::class, 'w');
        $qb->leftJoin('w.nomenclature', 'n');
        $qb->where("w.state NOT IN (:statuses)")
        ->setParameter('statuses', [Warehouse::STATUS_IN_WAREHOUSE, Warehouse::STATUS_PRODUCTION, Warehouse::STATUS_CLOSED])
        ->andWhere("w.payment_date IS NOT NULL");
        foreach ($conditionsArr as $oneCondition) {
            $qb->andWhere($oneCondition['condition']);
            if (isset($oneCondition['field']))
                $qb->setParameter($oneCondition['field'], $oneCondition['meaning']);
        }
        $payed_but_not_stocked = $qb->getQuery()->getSingleScalarResult(Query::HYDRATE_ARRAY);

        $stat['suppliers_balance'] = $payed_but_not_stocked - $stoked_but_not_payed;

        $result['stat'] = $stat;

        return $this->json($result);
    }

    /**
     * @Route("/list-detailer", name="warehouse_list_detailer", methods={"GET"})
     * @param WarehouseRepository $warehouseRepository
     * @return JsonResponse
     */
    public function listdetailer(Request $request, EntityManagerInterface $em, JWTEncoderInterface $JWTEncoder)
    {

        $user = $this->getUser();

        $userId = $user->getId();

        $qb = $em->createQueryBuilder();
        $qb->select('w')
        ->from(Warehouse::class, 'w')
        ->leftJoin('w.nomenclature', 'n')
        ->andWhere('w.responsible_id = :responsible_id')
        ->setParameter('responsible_id', $userId);

        $warehouses = $qb->getQuery()->getResult();
        $result = $this->queryToArray($warehouses);

        return $this->json($result);
    }

    /**
     * @Route("/{id}", methods={"DELETE"}, requirements={"id"="\d+"})
     * @param $id
     * @param SoftDeleteTransactionService $softDeleteTransactionService
     * @param EntityManagerInterface $em
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function removeWarehouseItem($id,
                                        SoftDeleteTransactionService $softDeleteTransactionService,
                                        EntityManagerInterface $em,
                                        Request $request)
    {
        $input = json_decode($request->getContent(), true);

        if ($input['is_final']) {
            $softDeleteTransactionService->deleteEntity(Warehouse::class, $id);
        } else {
            /** @var Warehouse $warehouse */
            $warehouse = $em->getRepository(Warehouse::class)->find($id);

            if (!$warehouse) {
                return $this->json([
                    'result' => 'error',
                    'type' => 'delete',
                    'id' => $id,
                    'message' => "No warehouse found for id $id"
                ], 404);
            }

            $em->remove($warehouse);
            $em->flush();
        }

        return $this->json([
            'result' => 'success',
            'type' => 'delete',
            'id' => $id
        ]);
    }

    /**
     * @Route("/removemass", methods={"POST"}, name="remove_mass")
     * @param Request $request
     * @param WarehouseRepository $warehouseRepository
     * @param EntityManagerInterface $em
     * @return JsonResponse
     */
    public function removeMassWarehouseItems(Request $request, WarehouseRepository $warehouseRepository, EntityManagerInterface $em)
    {
        $request_data = json_decode($request->getContent());

        foreach($request_data as $deleteId) {
            $warehouse = $warehouseRepository->find($deleteId);
            if (!$warehouse)
                return $this->json(['code' => '1023', 'message' => 'Позиция склада не найдена'], 404);

            $em->remove($warehouse);
        }

        $em->flush();

        return $this->json([]);
    }

    /**
     * @Route("/restore/{id}", methods={"POST"}, requirements={"id"="\d+"})
     * @param $id
     * @return JsonResponse
     * @throws Exception
     */
    public function restoreWarehouseItem($id, SoftDeleteTransactionService $softDeleteTransactionService)
    {
        $softDeleteTransactionService->restoreEntity(Warehouse::class, $id);

        return $this->json([]);
    }

    /**
     * @Route("/{id}", methods={"POST"}, requirements={"id"="\d+"})
     * @param $id
     * @param Request $request
     * @param WarehouseRepository $warehouseRepository
     * @param EntityManagerInterface $em
     * @return JsonResponse
     * @throws Exception
     */
    public function updateWarehouseItem($id, Request $request, WarehouseRepository $warehouseRepository, EntityManagerInterface $em)
    {
        $warehouse = $warehouseRepository->find($id);
        if (!$warehouse)
            return $this->json(['code' => '1023', 'message' => 'Позиция склада не найдена'], 404);

        $request_data = json_decode($request->getContent());

        $warehouse->setState($request_data->state->selected);
        $warehouse->setSupplierId($request_data->client->selected);
        $warehouse->setOrderDate($request_data->order_date ? new DateTime($request_data->order_date) : null);
        $warehouse->setDeliveryDate($request_data->delivery_date ? new DateTime($request_data->delivery_date) : null);
        $warehouse->setAccountNumber($request_data->account_number);
        $warehouse->setPaymentDate($request_data->payment_date ? new DateTime($request_data->payment_date) : null);
        $warehouse->setDocumentsStatus($request_data->documents_status);
        $warehouse->setEntity($request_data->entity->selected);
        $warehouse->setLocation($request_data->location->selected);
        $warehouse->setLocker($request_data->locker);
        $warehouse->setShelf($request_data->shelf);
        $warehouse->setComment($request_data->comment);

        $em->flush();

        return $this->json([]);
    }

    /**
     * @Route("/show-card/{id}", name="warehouse_show_card")
     * @param $id
     * @param WarehouseRepository $warehouseRepository
     * @return JsonResponse
     * @throws Exception
     */
    public function showCard($id, WarehouseRepository $warehouseRepository, EntityManagerInterface $em): JsonResponse
    {
        $em->getFilters()->disable('softdeleteable');

        $warehouse = $warehouseRepository->find($id);

        if (!$warehouse)
            throw new Exception('Warehouse item not found', 404);

        $result = $this->queryToArray([$warehouse]);

        return $this->json($result);
    }

    /**
     * @Route("/get-cutoffs/{id}", name="warehouse_get_cutoffs")
     * @param $id
     * @param WarehouseRepository $warehouseRepository
     * @return JsonResponse
     */
    public function getCutoffs($id, WarehouseRepository $warehouseRepository)
    {
        $warehouse = $warehouseRepository->find($id);
        $warehouseCutoffs = $warehouse->getRemainderSquare();
        return $this->json($warehouseCutoffs);
    }


    /********** Query to Array common function ***********
     * @param array $warehouses
     * @return array
     */
    private function queryToArray(array $warehouses)
    {

        $result = [];
        foreach ($warehouses as $warehouse) {
            $result[] = [
                'id' => $warehouse->getId(),
                'number' => $warehouse->getNumber(),
                'state' => $warehouse->getState(),
                'responsible_id' => $warehouse->getResponsibleId(),
                'order_date' => $warehouse->getOrderDate(),
                'delivery_date' => $warehouse->getDeliveryDate(),
                'supplier' => !is_null($warehouse->getSupplier()) ? $warehouse->getSupplier()->getId() : '',
                'supplier_title' => !is_null($warehouse->getSupplier()) ? $warehouse->getSupplier()->getTitle() : '',
                'account_number' => $warehouse->getAccountNumber(),
                'payment_date' => $warehouse->getPaymentDate(),
                'documents_status' => $warehouse->getDocumentsStatus(),
                'entity' => $warehouse->getEntity(),
                'location' => $warehouse->getLocation(),
                'locker' => $warehouse->getLocker(),
                'shelf' => $warehouse->getShelf(),
                'packing_volume' => $warehouse->getPackingVolume(),
                'purchase_price' => $warehouse->getPurchasePrice(),
                'unit_price' => $warehouse->getUnitPrice(),
                'remainder_volume' => $warehouse->getRemainderVolume(),
                'remainder_square' => $warehouse->getRemainderSquare(),
                'remainder_volume_before_prod' => $warehouse->getRemainderVolumeBeforeProd(),
                'remainder_square_before_prod' => $warehouse->getRemainderSquareBeforeProd(),
                'remainder_price' => $warehouse->getRemainderPrice(),
                'comment' => $warehouse->getComment(),
                'withdrawn_date' => $warehouse->getWithdrawnDate(),
                'nomenclature_id' => $warehouse->getNomenclature()->getId(),
                'nomenclature_name' => $warehouse->getNomenclature()->getName(),
                'nomenclature_unit' => $warehouse->getNomenclature()->getUnitName(),
                'nomenclature_price_per_package' => (float) $warehouse->getNomenclature()->getUnitPrice(),
                'nomenclature_quantity_in_package' => $warehouse->getNomenclature()->getQuantityInPack(),
                'nomenclature_line_meter_width' => (float) $warehouse->getNomenclature()->getLineMeterWidth(),
                'deleted_at' => $warehouse->getDeletedAt(),
                'from_partial_compensation' => $warehouse->getFromPartialCompensation(),
            ];
        }

        return $result;
    }

    /**
     * @Route("/withdraw", methods={"POST"})
     * @param Request $request
     * @param UserRepository $userRepository
     * @param EntityManagerInterface $entityManager
     * @param WarehouseRepository $warehouseRepository
     * @return JsonResponse
     */
    public function withdraw(Request $request,
                             UserRepository $userRepository,
                             EntityManagerInterface $entityManager,
                             WarehouseRepository $warehouseRepository)
    {
        $args = json_decode($request->getContent(), true);


        $responsible = $userRepository->find($args['responsible_id']);
        if (!$responsible)
            return $this->json(["error_code" => 2020, "error_message" => "Ответственный не найден"], 400);


        $warehouse_items = $warehouseRepository->findById($args['ids']);
        foreach ($warehouse_items as $item) {
            if ($item->getState() != Warehouse::STATUS_IN_WAREHOUSE)
                continue;
            $item->setState(Warehouse::STATUS_PRODUCTION);
            $item->setResponsible($responsible);
            $item->setRemainderVolumeBeforeProd($item->getRemainderVolume());
            $item->setRemainderSquareBeforeProd($item->getRemainderSquare());
        }
        $entityManager->flush();

        return $this->json([]);
    }

    /**
     * @Route("/take-from-production")
     * @param Request $request
     * @return mixed
     */
    public function takeFromProduction(Request $request,
                                       UserRepository $userRepository,
                                       EntityManagerInterface $entityManager,
                                       WarehouseRepository $warehouseRepository)
    {
        $args = json_decode($request->getContent(), true);
        if (empty($args['ids']))
            return new JsonResponse(['message' => 'Не задан параметр ids с идентификаторами принимаемых позиций'], 400);

        if (empty($args['responsible_id']))
            return new JsonResponse(['message' => 'Не задан идентификатор ответственного'], 400);

        $responsible = $userRepository->findOneById($args['responsible_id']);
        if (!$responsible)
            return new JsonResponse(['message' => 'Ответственный не найден'], 400);

        $warehouse_items = $warehouseRepository->findById($args['ids']);
        foreach ($warehouse_items as $item) {
            if ($item->getState() != Warehouse::STATUS_PRODUCTION)
                continue;
            $item->setState(Warehouse::STATUS_IN_WAREHOUSE);
            $item->setResponsible($responsible);
        }
        $entityManager->flush();

        return new JsonResponse();
    }

    /**
     * @Route("/change-status")
     * @param Request $request
     * @param WarehouseService $warehouseService
     * @return JsonResponse
     */
    public function changeStatus(Request $request, WarehouseService $warehouseService)
    {
        $args = json_decode($request->getContent(), true);
        if (!in_array(@$args['status'], Warehouse::STATUSES))
            return new JsonResponse(['message' => 'Неверный статус'], 400);
        # todo: подставить реального пользователя
        $warehouseService->bulkUpdatePossibleStatus(@$args['ids'], @$args['status']);
        return new JsonResponse();
    }

    /**
     * @Route("/warehouse-history/{id}", name="warehouse_history", methods={"GET"})
     * @param WarehouseRepository $warehouseRepository
     * @param $id
     * @return JsonResponse
     */
    public function warehousehistory($id, EntityManagerInterface $em)
    {
        $em->getFilters()->disable('softdeleteable');
        $qb = $em->createQueryBuilder();
        $qb->select('wc')
        ->from(WarehouseCorrection::class, 'wc')
        ->andWhere('wc.warehouse_item = :warehouse_item_id')
        ->setParameter('warehouse_item_id', $id)
        ->orderBy('wc.created_at', 'ASC');

        $warehouseCorrections = $qb->getQuery()->getResult();

        $qb = $em->createQueryBuilder();
        $qb->select('w')
        ->from(WarehouseConsumption::class, 'w')
        ->andWhere('w.warehouse_item = :warehouse_item_id')
        ->setParameter('warehouse_item_id', $id)
        ->andWhere('w.partial_compensation = 0')
        ->orderBy('w.created_at', 'ASC');

        $warehouseConsumptions = $qb->getQuery()->getResult();
        $result = [];
        foreach($warehouseConsumptions as $i => $warehouseConsumption) {
            $current = [];
            $workOrder = $warehouseConsumption->getWork()->getWorkOrder();
            $car = $workOrder->getDeal()->getCar();
            $generation = $car->getGeneration();
            $model = $generation->getModel();
            $modelTitle = $model->getTitle();
            $brand = $model->getCarBrand();
            $brandTitle = $brand->getTitle();
            $owner = $car->getOwner();
            $responsible = $warehouseConsumption->getResponsible();

            $current['created_at'] = !is_null($warehouseConsumption->getCreatedAt()) ? ($warehouseConsumption->getCreatedAt())->format('d.m.Y') : '-';
            $current['location'] = !is_null($workOrder->getLocation()) ? $workOrder->getLocation() : '-';
            $current['work_order'] = $workOrder->getId();
            $current['mark_model'] = $brandTitle . ' ' . $modelTitle;
            $current['owner'] = !is_null($owner) ? $owner->getFirstName() . ' '. $owner->getLastName() : '-';
            $current['description'] = $warehouseConsumption->getDescription();
            $current['responsible'] = !is_null($responsible) ? $responsible->getFirstName() . ' ' . $responsible->getLastName() : '-';
            $current['volume'] = !is_null($warehouseConsumption->getVolume()) ? $warehouseConsumption->getVolume() : 0;
            $current['square'] = !is_null($warehouseConsumption->getVolumeM2()) ? $warehouseConsumption->getVolumeM2() : [];
            $current['cutoffs'] = !is_null($warehouseConsumption->getCutoffs()) ? $warehouseConsumption->getCutoffs() : [];
            $warehouseConsumptionRemainders = $em->getRepository(WarehouseConsumptionRemainders::class)
                                                 ->findOneBy(["consumption_id" => $warehouseConsumption->getId()]);
            $current['current_remainder_volume'] = !is_null($warehouseConsumptionRemainders) ? $warehouseConsumptionRemainders->getRemainderVolume() : '-';
            $current['current_remainder_square'] = !is_null($warehouseConsumptionRemainders) ? $warehouseConsumptionRemainders->getRemainderSquare() : [];
            $current['partial_compensation'] = $warehouseConsumption->getPartialCompensation();
            $current['type_consumption'] = WarehouseConsumption::TYPES_NAMES[$warehouseConsumption->getType()];
            $result[] = $current;

            foreach($warehouseCorrections as $warehouseCorrection) {
                if ((isset($warehouseConsumptions[$i + 1])
                        && $warehouseCorrection->getCreatedAt() >= $warehouseConsumption->getCreatedAt()
                        && $warehouseCorrection->getCreatedAt() <= $warehouseConsumptions[$i + 1]->getCreatedAt())
                    ||
                    ($warehouseCorrection->getCreatedAt() >= $warehouseConsumption->getCreatedAt()
                        && !isset($warehouseConsumptions[$i + 1]))) {
                    $correction = [];
                    $correction['created_at'] = ($warehouseCorrection->getCreatedAt())->format('d.m.Y');
                    $correction['location'] = '-';
                    $correction['work_order'] = 0;
                    $correction['mark_model'] = '-';
                    $correction['owner'] = '-';
                    $correction['description'] = 'Корректировка ' . ($warehouseCorrection->getComment() != '' ? $warehouseCorrection->getComment() : '');
                    $correctionUser = $em->getRepository(User::class)->find($warehouseCorrection->getResponsibleId());
                    $correction['responsible'] = $correctionUser->getFirstName(). ' ' .$correctionUser->getLastName();
                    $correction['volume'] = 0;
                    $correction['square'] = [];
                    $correction['cutoffs'] = [];
                    $correction['current_remainder_volume'] = $warehouseCorrection->getNewVolume();
                    $correction['current_remainder_square'] = $warehouseCorrection->getNewCutoffs();
                    $correction['partial_compensation'] = 0;
                    $correction['type_consumption'] = '';
                    $result[] = $correction;
                }
            }
        }

        return $this->json($result);
    }

    public function deleteHistory($em, $entity, $entity_id, $action) {
        $deleteHistory = new DeleteHistory();
        $deleteHistory->setCreatedAt(new \DateTime());
        $deleteHistory->setEntity($entity);
        $deleteHistory->setEntityId($entity_id);
        $deleteHistory->setAction($action);
        $deleteHistory->setUser($em->getRepository(User::class)->find($this->getUser()->getId()));

        return $deleteHistory;
    }
}
