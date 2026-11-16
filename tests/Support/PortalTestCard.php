<?php

namespace Tests\Support;

use App\Portal\DashboardCard;

class PortalTestCard extends DashboardCard
{
    public function heading(): string
    {
        return 'Test card';
    }

    public function body(): string
    {
        return 'Test card body';
    }
}
