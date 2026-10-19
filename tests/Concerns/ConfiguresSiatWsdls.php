<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Services\Siat\SiatWsdlRegistry;

trait ConfiguresSiatWsdls
{
    protected function setUpConfiguresSiatWsdls(): void
    {
        Company::created(static function (Company $company): void {
            SiatWsdlRegistry::initializeCompany((int) $company->id);
        });
    }
}
