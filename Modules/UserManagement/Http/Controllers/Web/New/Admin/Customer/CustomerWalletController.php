<?php

namespace Modules\UserManagement\Http\Controllers\Web\New\Admin\Customer;

use App\Http\Controllers\BaseController;
use App\Service\BaseServiceInterface;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\TransactionManagement\Service\Interface\TransactionServiceInterface;
use Modules\UserManagement\Http\Requests\CustomerWalletStoreOrUpdateRequest;
use Modules\UserManagement\Service\Interface\CustomerAccountServiceInterface;

class CustomerWalletController extends BaseController
{
    use AuthorizesRequests;

    protected $customerAccountService;
    protected $transactionService;

    public function __construct(CustomerAccountServiceInterface $customerAccountService, TransactionServiceInterface $transactionService)
    {
        parent::__construct($customerAccountService);
        $this->customerAccountService = $customerAccountService;
        $this->transactionService = $transactionService;
    }

    public function index(?Request $request, string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $this->authorize('user_view');
        $request?->validate([
            'data' => Rule::in([ALL_TIME,THIS_WEEK,LAST_WEEK,THIS_MONTH,LAST_MONTH,THIS_YEAR]),
            'start' => 'required_if:data,custom_date',
            'end' => 'required_if:data,custom_date',
        ]);
        $transactions = $this->transactionService->customerWalletTransaction(data: $request?->all(), orderBy: ['created_at' => 'desc'], limit: paginationLimit(), offset:$request['page']??1);
        return view('usermanagement::admin.customer.wallet.index', compact('transactions'));
    }

    public function store(CustomerWalletStoreOrUpdateRequest $request)
    {
        $this->authorize('user_add');
        DB::beginTransaction();
        if ($request['customer_id'] == "all") {
            $relations = [
                'user' => [
                    ['user_type', '=', CUSTOMER],
                    ['is_active', '=', true],
                ],
            ];
            $whereHasRelations = [
                'user' => ['user_type' => 'CUSTOMER', 'is_active' => true]
            ];
            $customerAccountIds = $this->customerAccountService->getBy(whereHasRelations: $whereHasRelations, relations: $relations)->pluck('id')->toArray();
            $this->customerAccountService->updateManyWithIncrement(ids: $customerAccountIds, column: 'wallet_balance', amount: $request['amount']);
            $customerAccounts = $this->customerAccountService->getBy(whereHasRelations: $whereHasRelations, relations: $relations);
            foreach ($customerAccounts as $customerAccount) {
                $this->customerAccountService->createWalletTransaction(customer:$customerAccount,data: $request->validated());

                $push = getNotification('fund_added_by_admin');
                sendDeviceNotification(fcm_token: $customerAccount?->user?->fcm_token,
                    title: translate($push['title']),
                    description: translate(textVariableDataFormat(value: $push['description'],walletAmount: $request['amount'])),
                    ride_request_id: $customerAccount?->user?->id,
                    action: 'fund_added',
                    user_id: $customerAccount?->user?->id
                );
            }
        } else {
            $customerAccount = $this->customerAccountService->findOneBy(criteria: ['user_id' => $request['customer_id']]);
            $this->customerAccountService->update(id: $customerAccount->id, data:[
                'wallet_balance' => $customerAccount->wallet_balance + $request['amount']
            ]);
            $customerAccount = $this->customerAccountService->findOneBy(criteria: ['user_id' => $request['customer_id']],relations: ['user']);
            $this->customerAccountService->createWalletTransaction(customer:$customerAccount,data: $request->validated());
            $push = getNotification('fund_added_by_admin');
            sendDeviceNotification(fcm_token: $customerAccount->user->fcm_token,
                title: translate($push['title']),
                description: translate(textVariableDataFormat(value: $push['description'],walletAmount: $request['amount'])),
                ride_request_id: $customerAccount->user->id,
                action: 'fund_added',
                user_id: $customerAccount->user->id
            );
        }
        Toastr::success(CUSTOMER_FUND_STORE_200['message']);
        DB::commit();
        return redirect()->back();
    }

    public function export(Request $request)
    {
        $this->authorize('user_export');
        $transactions = $this->transactionService->customerWalletTransaction($request?->all(), orderBy: ['created_at' => 'desc']);
        $exportData = $this->customerAccountService->export($transactions);
        return exportData($exportData, $request['file'], 'usermanagement::admin.customer.wallet.print');
    }
}
