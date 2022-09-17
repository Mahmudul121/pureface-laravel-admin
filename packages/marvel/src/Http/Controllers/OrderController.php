<?php

namespace Marvel\Http\Controllers;

use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Delivery;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Enums\Permission;
use Marvel\Events\OrderCreated;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\OrderCreateRequest;
use Marvel\Http\Requests\OrderUpdateRequest;
use Illuminate\Support\Facades\DB;

class OrderController extends CoreController
{
    public $repository;

    public function __construct(OrderRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Order[]
     */
    public function index(Request $request)
    {
        $limit = $request->limit ?   $request->limit : 10;
        return $this->fetchOrders($request)->paginate($limit)->withQueryString();
    }

    public function fetchOrders(Request $request)
    {
        $user = $request->user();
        if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN) && (!isset($request->shop_id) || $request->shop_id === 'undefined')) {
            return $this->repository->with('children')->where('id', '!=', null)->where('parent_id', '=', null); //->paginate($limit);
        } else if ($this->repository->hasPermission($user, $request->shop_id)) {
            if ($user && $user->hasPermissionTo(Permission::STORE_OWNER)) {
                return $this->repository->with('children')->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null); //->paginate($limit);
            } elseif ($user && $user->hasPermissionTo(Permission::STAFF)) {
                return $this->repository->with('children')->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null); //->paginate($limit);
            }
        } else {
            return $this->repository->with('children')->where('customer_id', '=', $user->id)->where('parent_id', '=', null); //->paginate($limit);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param OrderCreateRequest $request
     * @return LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function store(OrderCreateRequest $request)
    {
        $user = $request->user();
        $orderProduct = $request->products;
        $productId = $orderProduct[0]['product_id'];

        $product = Product::find(42);
        $shop = $product->shop;
        $shopOwner = $shop->owner;
        $shopSettings = $shop->settings;
        $shopAddress = $shop->address;
        

        $orderDetail = $this->repository->storeOrder($request);

        $billingAddress = $orderDetail->billing_address;
        $shippingAddress = $orderDetail->shipping_address;

        $loginId = "LHR-02792";
        
        //get city of sender
        $cityUrl = "http://cod.callcourier.com.pk/API/CallCourier/GetOriginListByShipper?LoginId=" . $loginId;

        $city = $this->curlCall($cityUrl);
        $cityId = $city[0]->id ?? 1;

        // booking result
        $bookingUrl = "http://cod.callcourier.com.pk/api/CallCourier/BulkBookings";
        $bookingData = array(
            "loginId" => "LHR-02792",
            "ShipperName" => $shopOwner->name,
            "ShipperCellNo" => $shopSettings['contact'],
            "ShipperCity" => "1", //$shopAddress['city'],
            "ShipperArea" => "1", //$shopAddress['zip'],
            "ShipperAddress" => $shopAddress['street_address'],
            "ShipperReturnAddress" => $shopAddress['street_address'],
            "ShipperLandLineNo" => $shopSettings['contact'],
            "ShipperEmail" => $shopOwner->email,
            "BookingList" => array(
                array(
                    "index" => "1",
                    "ConsigneeName" => $user->name,
                    "ConsigneeRefNo" => $orderDetail->tracking_number,
                    "ConsigneeCellNo" => $orderDetail->customer_contact,
                    "Address" => $billingAddress['street_address'],
                    "DestCityId" => "1",
                    "ServiceTypeId" => "7",
                    "Pcs" => "01",
                    "Weight" => "01",
                    "Description" => "Test Description",
                    "SelOrigin" => "Domestic",
                    "CodAmount" => "100",
                    "SpecialHandling" => "false",
                    "MyBoxId" => "My Box ID",
                    "Holiday" => "false",
                    "remarks" => "Bulk Test Remarks 1"
                ),
            ),
        );

        $bookingResult = $this->curlCall($bookingUrl, $bookingData);

        


        $senderName = $shopOwner->name;
        $senderCity = $shopAddress['city'];

        foreach ($bookingResult->bookingResponse as $bookingItems) {
            
            // $delivery = new Delivery;
 
            // $delivery->ref_no = $bookingItems->refNo;
            // $delivery->net_amount = $bookingItems->NetAmount;
            // $delivery->amount = $bookingItems->Amount;
            // $delivery->gst_per = $bookingItems->GstPer;
            // $delivery->cnno = $bookingItems->CNNO;
            // $delivery->special_handling = $bookingItems->SpecialHandling;
            // $delivery->count = $bookingItems->Count;

            // $delivery->save();

            DB::table('deliveries')->insert([
                'shop_id' => $shop->id,
                'ref_no' => $bookingItems->refNo,
                'net_amount' => $bookingItems->NetAmount,
                'amount' => $bookingItems->Amount,
                'gst_per' => $bookingItems->GstPer,
                'cnno' => $bookingItems->CNNO,
                'special_handling' => $bookingItems->SpecialHandling,
                'count' => $bookingItems->Count,
                
                'sender_name' => $shopOwner->name,
                'sender_address' => $shopAddress['street_address'],
                'sender_mobile' => $shopSettings['contact'],
                'sender_email' => $shopOwner->email,
                
                'receiver_name' => $user->name,
                'receiver_address' => $billingAddress['street_address'] . ' ' . $billingAddress['city'] . ' ' . $billingAddress['country'],
                'receiver_mobile' => $request->customer_contact,
                'receiver_email' => $user->name,

                'cash' => $request->amount,
            ]);
        }


        // return $bookingResult->bookingResponse;
        /////////////////////////////////////////////////////

        return $this->repository->storeOrder($request);
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        try {
            $order = $this->repository->with(['products', 'status', 'children.shop'])->findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_FOUND');
        }
        if ($user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return $order;
        } elseif (isset($order->shop_id)) {
            if ($this->repository->hasPermission($user, $order->shop_id)) {
                return $order;
            } elseif ($user->id == $order->customer_id) {
                return $order;
            }
        } elseif ($user->id == $order->customer_id) {
            return $order;
        } else {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_AUTHORIZED');
        }
    }
    public function findByTrackingNumber(Request $request, $tracking_number)
    {
        $user = $request->user();
        try {
            $order = $this->repository->with(['products', 'status', 'children.shop'])->findOneByFieldOrFail('tracking_number', $tracking_number);
            if ($user->id === $order->customer_id || $user->can('super_admin')) {
                return $order;
            } else {
                throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_AUTHORIZED');
            }
        } catch (\Exception $e) {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_FOUND');
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param OrderUpdateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(OrderUpdateRequest $request, $id)
    {
        $request->id = $id;
        $order = $this->updateOrder($request);
        return $order;
    }


    public function updateOrder(Request $request)
    {
        try {
            $order = $this->repository->findOrFail($request->id);
        } catch (\Exception $e) {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_FOUND');
        }
        $user = $request->user();
        if (isset($order->shop_id)) {
            if ($this->repository->hasPermission($user, $order->shop_id)) {
                return $this->changeOrderStatus($order, $request->status);
            }
        } else if ($user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return $this->changeOrderStatus($order, $request->status);
        } else {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_AUTHORIZED');
        }
    }

    public function changeOrderStatus($order, $status)
    {
        $order->status = $status;
        $order->save();
        try {
            $children = json_decode($order->children);
        } catch (\Throwable $th) {
            $children = $order->children;
        }
        if (is_array($children) && count($children)) {
            foreach ($order->children as $child_order) {
                $child_order->status = $status;
                $child_order->save();
            }
        }
        return $order;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (\Exception $e) {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_FOUND');
        }
    }

    public function getDeliveries(Request $request) {
        $limit = $request->limit ?   $request->limit : 10;
        $searchText = $request->search ? $request->search : "";
        return DB::table('deliveries')
            // ->orderBy('name', 'desc')
            ->where('shop_id', '=', $request->shop_id)
            ->where(function($query) use ($searchText){
                $query->where('sender_name', 'like', '%' . $searchText . '%')
                ->orWhere('receiver_name', 'like', '%' . $searchText . '%');
            })
            ->paginate($limit);
    }

    public function curlCall($apiurl, $data = array()) {

        $ch = curl_init($apiurl);

        if ($data) {
            $payload = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        curl_close($ch);

        $bookingResult = json_decode($result);

        return $bookingResult;
    }
}
