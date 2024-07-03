<?php

namespace craftsnippets\craftgls\models;
use craft\base\Model;


class ParcelStatus extends Model
{

    public const DELIVERED = 5;
    public const DELIVERED_TO_NEIGHBOUR = 58;

    public $depotCity;

    /**
     * @var string
     */
    public $depotNumber;

    /**
     * @var string
     */
    public $statusCode;

    /**
     * @var string
     */
    public $statusDate;

    /**
     * @var string
     */
    public $statusDescription;

    /**
     * @var string
     */
    public $statusInfo;

    public function getIsDelivered()
    {
        return $this->statusCode == self::DELIVERED || $this->statusCode == self::DELIVERED_TO_NEIGHBOUR;
    }

}