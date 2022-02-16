<?php

namespace IgniterLabs\CommissionCalculator;

use Closure;
use Illuminate\Database\Eloquent\Model;

class Calculator
{
    protected $orderTotal;

    protected $calculatedFee;

    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $order;

    /**
     * @var array|\Illuminate\Support\Collection
     */
    protected $rules;

    /**
     * @var array|\Illuminate\Support\Collection
     */
    protected $conditions;

    /**
     * @var array|\Illuminate\Support\Collection
     */
    protected $totals;

    protected $whenMatched;

    protected $beforeFilter;

    public static function create()
    {
        return new static;
    }

    public function useOrder(Model $order)
    {
        $this->order = $order;

        return $this;
    }

    public function withRules($rules)
    {
        $this->rules = $rules;

        return $this;
    }

    public function withConditions($conditions)
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function withTotals($totals)
    {
        $this->totals = $totals;

        return $this;
    }

    public function whenMatched(Closure $callback)
    {
        $this->whenMatched = $callback;

        return $this;
    }

    public function beforeFilter(Closure $callback)
    {
        $this->beforeFilter = $callback;

        return $this;
    }

    public function calculate()
    {
        $this->sumOrderTotal();

        $calculatedFee = collect($this->rules)
            ->map(function ($rule) {
                return $this->processRule($rule);
            })
            ->filter(function ($rule) {
                return $this->filterRule($rule);
            })
            ->reduce(function ($commissionFee, $rule) {
                if ($this->whenMatched)
                    call_user_func($this->whenMatched, $rule, $this->orderTotal);

                return $commissionFee + $rule->calculatedFee;
            }, 0);

        $this->calculatedFee = $calculatedFee;

        return $this;
    }

    public function getCalculatedFee()
    {
        return $this->calculatedFee;
    }

    public function getOrderTotal()
    {
        return $this->orderTotal;
    }

    protected function sumOrderTotal()
    {
        $totalCodes = collect($this->conditions)
            ->where('action', 'split')
            ->pluck('code')
            ->push(['subtotal'])
            ->all();

        $orderTotal = collect($this->totals)->whereIn('code', $totalCodes)->sum('value');

        $this->orderTotal = (float)number_format($orderTotal, 2, '.', '');
    }

    protected function sumOrderTotalValue($code)
    {
        return collect($this->totals)->where('code', $code)->sum('value');
    }

    protected function processRule($rule)
    {
        if (is_array($rule))
            $rule = (object)$rule;

        $fee = $rule->fee_type === 'percent'
            ? ($rule->fee / 100) * $this->orderTotal
            : $rule->fee;

        $calculatedFee = collect($this->conditions ?? [])
            ->filter(function ($total) {
                return $total['action'] === 'include';
            })
            ->reduce(function ($calculatedFee, $total) {
                return $calculatedFee + $this->sumOrderTotalValue($total['code']);
            }, number_format($fee, 2, '.', ''));

        $rule->calculatedFee = $calculatedFee;

        return $rule;
    }

    protected function filterRule($rule)
    {
        if ($this->beforeFilter AND call_user_func($this->beforeFilter, $rule, $this->orderTotal))
            return FALSE;

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
        return collect($conditions)
            ->sortBy('priority')
            ->every(function ($condition) {
                $attribute = array_get($condition, 'attribute');
                $operator = array_get($condition, 'operator');
                $conditionValue = mb_strtolower(trim(array_get($condition, 'value')));
                $modelValue = $this->getOrderAttribute($attribute);

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

                return FALSE;
            });
    }

    protected function getOrderAttribute($attribute)
    {
        return mb_strtolower(trim($this->order->{$attribute}));
    }
}
