<?php

namespace IgniterLabs\CommissionCalculator;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Calculator
{
    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $order;

    /**
     * @var \Illuminate\Support\Collection
     */
    protected $rules;

    /**
     * @var \Illuminate\Support\Collection
     */
    protected $totals;

    protected $whenMatched = [];

    protected $beforeFilter = [];

    protected $orderTotal;

    protected $calculatedTotals;

    public static function on(Model $order)
    {
        $instance = new static;
        $instance->order = $order;
        $instance->rules = collect([]);
        $instance->conditions = collect([]);
        $instance->totals = collect([]);

        return $instance;
    }

    public function withRules(Collection $rules)
    {
        $this->rules = $this->rules->merge($rules);

        return $this;
    }

    public function withTotals(Collection $totals)
    {
        $this->totals = $this->totals->merge($totals);

        return $this;
    }

    public function whenMatched(Closure $callback)
    {
        $this->whenMatched[] = $callback;

        return $this;
    }

    public function beforeFilter(Closure $callback)
    {
        $this->beforeFilter[] = $callback;

        return $this;
    }

    public function calculate()
    {
        $this->sumOrderTotal();

        return $this->rules
            ->map(function ($rule) {
                return $this->processRule($rule);
            })
            ->filter(function ($rule) {
                return $this->filterRule($rule);
            })
            ->reduce(function ($commissionFee, $rule) {
                foreach ($this->whenMatched as $callback) {
                    $callback($rule, $this->orderTotal);
                }

                return $commissionFee + $rule->calculatedFee;
            }, 0);
    }

    public function getOrderTotal()
    {
        return $this->orderTotal;
    }

    protected function sumOrderTotal()
    {
        $this->orderTotal = (float)number_format(
            $this->totals->whereIn('code', ['subtotal'])->sum('value'), 2, '.', ''
        );
    }

    protected function sumOrderTotalValue($code)
    {
        return $this->totals->where('code', $code)->sum('value');
    }

    protected function processRule($rule)
    {
        if (is_array($rule))
            $rule = (object)$rule;

        $rule->calculatedTotals = [];

        $fee = $rule->fee_type === 'percent'
            ? ($rule->fee / 100) * $this->orderTotal
            : $rule->fee;

        $calculatedFee = collect($rule->totals ?? [])
            ->filter(function ($total) {
                return $total['action'] === 'include';
            })
            ->reduce(function ($calculatedFee, $total) use ($rule) {
                $rule->calculatedTotals[] = $total['code'];

                return $calculatedFee + $this->sumOrderTotalValue($total['code']);
            }, (float)number_format($fee, 2, '.', ''));

        $rule->calculatedFee = $calculatedFee;

        return $rule;
    }

    protected function filterRule($rule)
    {
        foreach ($this->beforeFilter as $callback) {
            if ($callback($rule, $this->orderTotal))
                return FALSE;
        }

        if (isset($rule->conditions))
            return $this->evalRuleConditions($rule->conditions);

        if ($rule->type === 'below')
            return $this->orderTotal < $rule->total;

        if ($rule->type === 'above')
            return $this->orderTotal >= $rule->total;

        return TRUE;
    }

    protected function evalRuleConditions($conditions)
    {
        $result = collect($conditions)
            ->sortBy('priority')
            ->every(function ($condition) {
                $attribute = array_get($condition, 'attribute');
                $operator = array_get($condition, 'operator');
                $conditionValue = mb_strtolower(trim(array_get($condition, 'value')));
                $modelValue = mb_strtolower(trim($this->order->{$attribute}));

                if ($operator == 'is')
                    return $modelValue == $conditionValue;

                if ($operator == 'is_not')
                    return $modelValue != $conditionValue;

                if ($operator == 'greater')
                    return $modelValue > $conditionValue;

                if ($operator == 'less')
                    return $modelValue < $conditionValue;

                if ($operator == 'contains')
                    return mb_strpos($modelValue, $conditionValue) !== FALSE;

                if ($operator == 'does_not_contain')
                    return mb_strpos($modelValue, $conditionValue) === FALSE;

                if ($operator == 'equals_or_greater')
                    return $modelValue >= $conditionValue;

                if ($operator == 'equals_or_less')
                    return $modelValue <= $conditionValue;

                return TRUE;
            });

        return $result === TRUE;
    }
}
