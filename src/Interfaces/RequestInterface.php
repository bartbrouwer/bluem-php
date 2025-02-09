<?php

namespace Bluem\BluemPHP\Interfaces;

use Bluem\BluemPHP\Requests\BluemRequest;
use SimpleXMLElement;

interface RequestInterface {
    
    public function getContext();

    public function XmlString(): string;

    public function Xml(): SimpleXMLElement;

    public function Print();

    public function HttpRequestURL(): string;

    public function retrieveBICObjects(): array;

    public function retrieveBICCodes(): array;

    public function selectDebtorWallet( $BIC );

    public function XmlWrapDebtorWallet(): string;

    public function XmlWrapDebtorAdditionalData(): string;

    public function addAdditionalData( $key, $value ): BluemRequest;

    public function RequestContext();

    public function RequestType(): string;
}