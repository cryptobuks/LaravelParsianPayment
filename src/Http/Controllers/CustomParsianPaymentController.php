<?php

namespace Alive2212\LaravelParsianPayment\Http\Controllers;

use Alive2212\LaravelParsianPayment\AliveParsianPayment;
use Alive2212\LaravelSmartResponse\ResponseModel;
use Alive2212\LaravelSmartResponse\SmartResponse\SmartResponse;
use Alive2212\LaravelSmartRestful\BaseController;
use Alive2212\LaravelStringHelper\StringHelper;
use App\Jobs\ParsianPaymentConfirmedJob;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
use SoapClient;
use Exception;


class CustomParsianPaymentController extends BaseController
{

    protected $localPrefix = 'laravel-parsian-payment';

    /**
     *
     */
    public function initController()
    {
        $this->model = new AliveParsianPayment();
        $this->middleware([
            'auth:api',
        ])->except(['confirm']);
    }

    /**
     * @var string
     */
    protected $PIN = '';

    /**
     * @var null
     */
    protected $orderId;

    /**
     * @var string
     */
    protected $PAYMENT_URL;

    /**
     * @var string
     */
    protected $IPG_URL;

    /**
     * @var
     */
    protected $CONFIRM_URL;

    /**
     * @var
     */
    protected $CALLBACK_URL;

    /**
     * @return int
     */
    public function generateOrderId()
    {
        return ($this->randomTimeNumber());
    }

    /**
     * @param int $randomLength
     * @return int
     */
    public function randomTimeNumber($randomLength = 4)
    {
        return (date('Ymdhis') . rand(pow(10, $randomLength), pow(10, $randomLength + 1) - 1));
    }

    //  this function is to get proper authority key from Parsian
    public function init(Request $request)
    {
        $response = new ResponseModel();

        $validationErrors = $this->checkRequestValidation($request, [
            'amount' => 'required',
        ]);
        if ($validationErrors != null) {
            if (env('APP_DEBUG', false)) {
                $response->setData(collect($validationErrors->toArray()));
            }
            $response->setMessage($this->getTrans(__FUNCTION__, 'validation_failed'));
            $response->setStatus(false);
            $response->setError(99);
            return SmartResponse::response($response);
        }

        // generate order id
        $this->orderId = $this->generateOrderId();

        try {
            $soapClient = new SoapClient(config('laravel-parsian-payment.url.payment'));
            $salePaymentResponse = $soapClient->SalePaymentRequest(array(
                "requestData" => array(
                    'LoginAccount' => config('laravel-parsian-payment.pin'),  // this is our PIN NUMBER
                    'Amount' => $request['amount'], //get amount of payment request
                    'OrderId' => $this->orderId, // get unique generated order id in constructor
                    'CallBackUrl' => config('laravel-parsian-payment.url.callback'), // site call back Url
                )
            ));

            $response->setData(collect($salePaymentResponse->SalePaymentRequestResult));
            $response->getData()->put('order_id', $this->orderId);

            if ($salePaymentResponse->SalePaymentRequestResult->Token && $salePaymentResponse->SalePaymentRequestResult->Status === 0) {
                $response->getData()->put('url', config('laravel-parsian-payment.url.ipg') . $salePaymentResponse->SalePaymentRequestResult->Token);

            } elseif ($salePaymentResponse->SalePaymentRequestResult->Status != '0') {
                $response->setMessage($salePaymentResponse->SalePaymentRequestResult->Message);
                $response->setStatus('false');
                $error = $salePaymentResponse->SalePaymentRequestResult->Status;
                $response->setError($error);
            }

        } catch (Exception $exception) {
            $response->setMessage($exception->getMessage());
            $response->setStatus('false');
        }

        // convert data
        $response->setData($this->dataAdaptor($response->getData()));

        //add user id into data
        $response->getData()->put('user_id', auth()->id());

        // add base deep url
        if ($request->has('base_deep_link')) {
            $response->getData()->put('base_deep_link', $request->get('base_deep_link'));
        }

        // store response to record
        $parsianPayment = new AliveParsianPayment();
        $parsianPayment->create($response->getData()->toArray());

        //init response
        return SmartResponse::response($response);
//        dd('I have most powerful forces of Dokhan');
    }

    public function confirm(Request $request)
    {
        //TODO goto middle ware
        //check for confirmed payment and return back to app
        $parsianPayment = new AliveParsianPayment();
        $payment = $parsianPayment->where('order_id', '=', $request['OrderId'])
            ->first();
        if (!is_null($payment)) {
            if ($payment->toArray()['status'] == 'confirmed') {
                return redirect(
                    $this->getBaseDeepLink($payment->toArray()) .
                    '?status=' .
                    config('laravel-parsian-payment.url.successful') .
                    '&payment_order_id=' .
                    $payment->toArray()['customer_order_id']);
            }
        }

        // get response
        $response = $this->confirmFromBank($request);

        // get data from response
        $data = $response->getData();

        // log to debug
        Log::info('response ==> ' . json_encode($data));

        // create or update a record of data base
        (new AliveParsianPayment())->firstOrCreate([
            'order_id' => $data->get('order_id'),
        ])->update($data->toArray());

        //TODO delete this codes
        if ($response->getStatus() == "true") {
            ParsianPaymentConfirmedJob::dispatch(
                (new AliveParsianPayment())->firstOrCreate([
                    'order_id' => $data->get('order_id'),
                ])->toArray());
            return redirect(
                $this->getBaseDeepLink($payment->toArray()) .
                '?status=' .
                config('laravel-parsian-payment.url.successful') .
                '&payment_order_id=' .
                $request['OrderId']);

        } else {
            return redirect(
                $this->getBaseDeepLink($payment->toArray()) .
                '?status=' .
                config('laravel-parsian-payment.url.failed') .
                '&payment_order_id=' .
                $request['OrderId']);
        }
    }

    public function confirmFromBank(Request $request)
    {

        $response = new ResponseModel();
        $response->getData()->put('token', $request['Token']);
        $response->getData()->put('status_code', $request['status']);
        $response->getData()->put('order_id', $request['OrderId']);
        $response->getData()->put('terminal_number', $request['TerminalNo']);
        $response->getData()->put('amount', $request['Amount']);
        $response->getData()->put('rrn', $request['RRN']);

        $status = 'callback';

        //check for valid payment
        if ($response->getData()->get('rrn') > 0 && $response->getData()->get('status_code') == 0) {

            $status = 'confirming';

            $soapClient = new SoapClient (config('laravel-parsian-payment.url.confirm'));

            try {

                $confirmResponse = $soapClient->ConfirmPayment([
                    "requestData" => [
                        "LoginAccount" => config('laravel-parsian-payment.pin'),
                        "Token" => $response->getData()->get('token'),
                    ],
                ]);

                // fill data
                foreach ($confirmResponse->ConfirmPaymentResult as $key => $value) {
                    $response->getData()->put($key, $value);
                }

                //TODO Uncomment Here For Test ___________________________________
//                $response->setData(collect([
//                    'Token' => 39795698,
//                    'status_code' => "0",
//                    'OrderId' => "2018011110022388345",
//                    'TerminalNumber' => "44423934",
//                    'Amount' => "10000",
//                    'RRN' => 706689733478,
//                    'CardNumberMasked' => "502938******4826",
//                ]));
//                $status = 'confirmed';
                //TODO To Here ___________________________________

                // convert data
                $response->setData($this->dataAdaptor($response->getData()));

                //Log
                Log::info($response->getData()->toJson());

                //TODO Comment Here For Test ___________________________________
                if ($confirmResponse->ConfirmPaymentResult->Status != '0') {
                    $status = 'confirmation_failed';
                    $response->setError(110);
                    $response->setMessage('confirmation_failed');
                    $response->setStatus("false");
                } else {
                    $status = 'confirmed';
                }
                //TODO To Here ___________________________________

            } catch (Exception $exception) {
                $status = 'confirming_failed';
                $response->setError(111);
                $response->setMessage($exception->getMessage());
                $response->setStatus("false");
            }

        } elseif (is_numeric($response->getData()->get('status_code'))) { // known error from bank
            $status = 'callback_failed';
            $response->setError(112);
            $response->setMessage('callback_failed');
            $response->setStatus("false");

        } else { // dos'nt any response from bank
            $status = 'connection_failed';
            $response->setError(113);
            $response->setMessage('connection_failed');
            $response->setStatus("false");
        }

        $response->getData()->put('status', $status);

        return $response;
    }

    /**
     * @return mixed
     */
    public function getOrderId()
    {
        return $this->orderId;
    }

    /**
     * @param mixed $orderId
     */
    public function setOrderId($orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * @param Collection $data
     * @return Collection
     */
    public function dataAdaptor(Collection $data)
    {
        $result = new Collection();
        foreach ($data as $key => $value) {
            if ($key == 'Status') {
                $key = 'status_code';
            }
            if ($key == 'amount') {
                $value = str_replace(',', '', $value);
            }
            if ($key == 'RRN') {
                $key = 'rrn';
            }

            $result->put((new StringHelper())->toTag(($key)), $value);
        }
        return $result;
    }

    /**
     * @param array $payment
     * @return string
     */
    public function getBaseDeepLink(array $payment): string
    {
        if (is_null($payment['base_deep_link'])){
            return config('laravel-parsian-payment.url.base_deep_link');
        }
        return $payment['base_deep_link'];
    }

}