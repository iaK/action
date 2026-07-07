<?php

namespace Iak\Action\Tests\TestClasses;

enum OrderEvent: string
{
    case Placed = 'order.placed';
    case Shipped = 'order.shipped';
}
