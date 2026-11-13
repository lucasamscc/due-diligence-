<?php

namespace App\Rules\Rules;

use App\Diligence\Models\Finding;
use App\Rules\Contracts\RiskRule;
use App\Rules\RiskContext;
use App\Sources\Data\FindingType;
use Carbon\CarbonImmutable;
use Closure;

class RegistrationStatusRule implements RiskRule
{
    public function handle(RiskContext $context, Closure $next): RiskContext
    {
        /** @var Finding|null $cadastral */
        $cadastral = $context->findings->first(fn (Finding $f) => $f->type === FindingType::Cadastral);

        if ($cadastral !== null) {
            $d = $cadastral->details;

            if (($d['found'] ?? true) === false) {
                $context->alert('cnpj_not_found', 'high', 'CNPJ não encontrado na base da Receita Federal.', 40, [$cadastral->id]);
            } elseif (($d['situacao_cadastral'] ?? 'ATIVA') !== 'ATIVA') {
                $context->alert('registration_not_active', 'high', "Situação cadastral: {$d['situacao_cadastral']}.", 40, [$cadastral->id]);
            }

            if (! empty($d['data_inicio_atividade']) && CarbonImmutable::parse($d['data_inicio_atividade'])->gt(now()->subYear())) {
                $context->alert('recently_opened', 'low', 'Empresa aberta há menos de 1 ano.', 10, [$cadastral->id]);
            }
        }

        return $next($context);
    }
}
