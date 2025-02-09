<?php
/* Reference: https://www.codementor.io/@sirolad/validating-xml-against-xsd-in-php-6f56rwcds */

namespace Bluem\BluemPHP\Validators;

use Bluem\BluemPHP\Contexts\BluemContext;
use DOMDocument;
use DOMException;
use Exception;
use libXMLError;

// @todo: consider removing subclasses

class BluemXMLValidator {
    /**
     * @var int
     */
    public $feedErrors = 0;
    /**
     * Formatted libxml Error details
     *
     * @var array
     */
    public $errorDetails;
    /**
     * XSD Schema definition location, to be set by context
     *
     * @var string
     */
    protected $feedSchema = "";
    /**
     * @var DOMDocument
     */
    private $handler;

    /**
     * Validation Class constructor Instantiating DOMDocument
     *
     * @param string|null $feedSchema
     */
    public function __construct( string $feedSchema = null ) {
        $this->handler    = new DOMDocument( '1.0', 'utf-8' );
        $this->feedSchema = $feedSchema;
    }

    /**
     * Validate Incoming Feeds against Listing Schema
     *
     * @param BluemContext $context
     * @param              $contents
     *
     * @return bool
     * @throws DOMException
     * @throws Exception
     */
    public function validate( BluemContext $context, $contents ): bool {
        $this->feedSchema = $context->getValidationSchema();

        if ( ! class_exists( 'DOMDocument' ) ) {
            throw new DOMException(
                "'DOMDocument' class not found!"
            );
        }
        if ( ! file_exists( $this->feedSchema ) ) {
            throw new Exception(
                "Schema is Missing, Please add schema to feedSchema property"
            );
        }

        libxml_use_internal_errors( true );
        // if (!($fp = fopen($feeds, "r"))) {
        //    die("could not open XML input");
        // }
        // $contents = fread($fp, filesize($feeds));
        // fclose($fp);

        $this->handler->loadXML( $contents, LIBXML_NOBLANKS );
        if ( ! $this->handler->schemaValidate( $this->feedSchema ) ) {
            $this->errorDetails = $this->libxmlDisplayErrors();
            $this->feedErrors   = 1;

            return false;
        } else {
            //The file is valid
            return true;
        }
    }

    /**
     * @return array
     */
    private function libxmlDisplayErrors(): array {
        $errors = libxml_get_errors();
        $result = [];
        foreach ( $errors as $error ) {
            $result[] = $this->libxmlDisplayError( $error );
        }
        libxml_clear_errors();

        return $result;
    }

    /**
     * @param libXMLError object $error
     *
     * @return string
     */
    private function libxmlDisplayError( $error ): string {
        $errorString = "Error $error->code in $error->file (Line: $error->line):";
        $errorString .= trim( $error->message );

        return $errorString;
    }

    /**
     * Display Error if Resource is not validated
     *
     * @return array
     */
    public function displayErrors(): array {
        return $this->errorDetails;
    }
}
