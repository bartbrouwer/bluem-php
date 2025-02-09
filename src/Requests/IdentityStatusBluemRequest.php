<?php

namespace Bluem\BluemPHP\Requests;

use Bluem\BluemPHP\Contexts\IdentityContext;
use Bluem\BluemPHP\Interfaces\BluemRequestInterface;

class IdentityStatusBluemRequest extends BluemRequest implements BluemRequestInterface {
    public $request_url_type = "ir";
    public $typeIdentifier = "requestStatus";
    public $transaction_code = "ISX";
    protected $xmlInterfaceName = "IdentityInterface";

    public function __construct( $config, $entranceCode, $expectedReturn, $transactionID ) {
        parent::__construct( $config, $entranceCode, $expectedReturn );

        // override specific brand ID when using IDIN
        if ( isset( $config->IDINBrandID ) && $config->IDINBrandID !== "" ) {
            $config->setBrandId( $config->IDINBrandID );
        } else {
            $config->setBrandId( $config->brandID );
        }

        $this->transactionID = $transactionID;

        $this->context = new IdentityContext();
    }

    // @todo: deprecated, remove

    public function TransactionType(): string {
        return "ISX";
    }

    public function XmlString(): string {
        return $this->XmlRequestInterfaceWrap(
            $this->xmlInterfaceName,
            'StatusRequest',
            $this->XmlRequestObjectWrap(
                'IdentityStatusRequest',
                '<TransactionID>' . $this->transactionID . '</TransactionID>'
            )
        );
    }
}
