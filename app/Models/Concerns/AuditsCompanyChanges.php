<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\CompanyContext;
use OwenIt\Auditing\Auditable;

trait AuditsCompanyChanges
{
    use Auditable;

    public function transformAudit(array $data): array
    {
        $data['company_id'] = $this->auditCompanyId();

        return $data;
    }

    public function generateTags(): array
    {
        return array_filter([
            'company:'.($this->auditCompanyId() ?? 'global'),
            class_basename($this),
        ]);
    }

    private function auditCompanyId(): ?int
    {
        $companyId = CompanyContext::id();

        if ($companyId !== null) {
            return $companyId;
        }

        if ($this instanceof Company && $this->getKey() !== null) {
            return (int) $this->getKey();
        }

        if ($this->getAttribute('company_id') !== null) {
            return (int) $this->getAttribute('company_id');
        }

        return auth()->user()?->company_id ? (int) auth()->user()->company_id : null;
    }
}
