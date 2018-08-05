<?php

return [
    'pin' => '',
    'url' => [
        'payment' => 'https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx?WSDL',
        'ipg' => 'https://pec.shaparak.ir/NewIPG/?Token=',
        'callback' => '{https:// your base url}/',// it should be https in otherwise it will crashed with ios payment
        'confirm' => 'https://pec.shaparak.ir/NewIPGServices/Confirm/ConfirmService.asmx?WSDL',
        'successful' => 'your app deep link',
        'failed' => 'your app deep link',
    ],
];