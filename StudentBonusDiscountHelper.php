<?php

namespace  App\Helpers;

use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\StudentPayment;
use App\Models\StudentBonusPayment;

class StudentBonusDiscountHelper
{

    private $student;
    private $bonus_amount;
    private $bonus_settings;
    //scholarship skip for now, can divide if needed
    //private $scholarship_balance;
    private $bonus_balance;
    private $have_discount;
    const BONUS_TYPES = [
        'bonus_registration',
        'bonus_books',
        'bonus_theory',
        'bonus_full_training',
        'bonus_omniva',
        'bonus_pmp',
        'bonus_school_exam',
        'bonus_extra_theory',
        'bonus_school_driving',
        'bonus_external_driving',
        'bonus_school_driving_exam',
        'bonus_external_driving_exam',
        'bonus_csdd_test_subscription'
    ];

    const DRIVING_BONUS_TYPES = [
        'bonus_school_driving',
        'bonus_external_driving',
        'bonus_school_driving_exam',
        'bonus_external_driving_exam'
    ];

    public function __construct(Student $student, ?float $bonus_amount = null)
    {
        $this->student = $student;
        $this->bonus_amount = $bonus_amount ?? $this->student->bonus_amount;
        $this->bonus_settings = optional($this->student)->bonus_settings;
        //$this->scholarship_balance =  $this->bonus_amount; //optional($this->student->have_some_discount)->scholarship_balance;
        $this->bonus_balance =  $this->bonus_amount; //optional($this->student->have_some_discount)->bonus_balance;
        $this->have_discount = optional($this->student->have_some_discount)->have_discount;
    }

    public function getDiscounts(float $amount, ?string $type = null) //$bonus_settings, $bonus_balance, $scholarship_balance,
    {
        $this->bonus_settings = optional($this->student)->bonus_settings;
        //legacy from driving, mb some day will be needed
        $daily = null;
        $check_bonus = (!is_null($type) and $this->have_discount);
        //$scholarship = $this->getScholarship($amount, $type, $check_bonus);
        $bonus = $this->getBonus($amount, $type, $check_bonus);
        $discount = null;
        if (optional($daily)->has_discount) {
            $discount = $daily;
        }
        /*elseif (optional($scholarship)->have_scholarship) {
            $discount = $scholarship;
        }*/ elseif (optional($bonus)->have_bonus) {
            $discount = $bonus;
        }
        return (object)[
            //'scholarship' => $scholarship,
            'bonus' => $bonus,
            'daily' => $daily,
            'has_discount' =>  (int)(optional($daily)->has_discount or optional($bonus)->have_bonus), //or optional($scholarship)->have_scholarship
            'discount' => $discount,
        ];
    }   

    public function getBonus(float $amount, ?string $type = null, bool $check_bonus = true): object
    {
        $result = new \StdClass();
        $result->discount_amount = (int) optional($this->bonus_settings)->$type;
        if ($result->discount_amount <= 0) {
            $check_bonus = false;
        }
        $result->bonus_balance = $this->bonus_balance;
        $percent_data = $this->getPercentData($result->discount_amount, $check_bonus, $result->bonus_balance, $amount);
        $result->discount_amount = optional($percent_data)->discount_amount;
        $result->discount_max_amount = optional($percent_data)->discount_max_amount;
        $result->discount = optional($percent_data)->discount;
        $result->discounted_rate = optional($percent_data)->discounted_rate;
        $result->sum = optional($percent_data)->sum;
        $result->discount_type = 'bonus';
        $result->have_bonus = $check_bonus ? (int)(
            optional($this->student->have_some_discount)->have_bonus
            and
            // ($result->discount_amount <= optional($percent_data)->bonus_percent)
            // and
            ($result->bonus_balance >= optional($percent_data)->discount)
        ) : 0;
        return $result;
    }

    private function getPercentData(?int $discount_percent = null, bool $check_discount = true, ?float $balance = null, ?float $amount = null): object
    {
        $discount_max_amount = $discount_percent;
        $result = new \StdClass();
        $discount = 0;
        $discount_amount = 0;
        $sum_discount = 0;
        $sum = abs($amount);
        if ($check_discount and (int)$discount_percent) {
            $percent = ($discount_percent * 0.01);
            $discount = round(($sum * $percent), 2);
            //check if balance can afford this shit
            if ($discount > $balance) {
                $discount = $balance;
                if ($sum) {
                    $discount_amount = round((($balance / $sum) * 100), 2);
                }
            } else {
                $discount_amount = $discount;
            }
            $sum_discount = $sum - $discount_amount;
            if ($sum_discount < 0) {
                $sum_discount = 0;
            }
        }
        $result->discount = $discount;
        $result->discount_amount = (float)round($discount_amount, 2);
        $result->discount_max_amount = (float)round($discount_max_amount, 2);
        $result->discounted_rate = number_format($sum_discount, 2);
        return $result;
    }

    public function createBonusPayment(Student $student, $discounts, ?string $reason = null, ?float $discount = null, ?int $payment_id = null, ?float $forced_amount = null): ?int
    {
        $scholarship = (int)(optional($discounts)->discount->discount_type == 'scholarship');
        $discount = $discount ?? ($scholarship ? $discounts->scholarship->discount : $discounts->bonus->discount);
        try {
            $new_bonus = null;
            $id = null;
            //make bonus payment
            if (!is_null($payment_id)) {
                $new_bonus = StudentBonusPayment::where('payment_id', $payment_id)->first() ?? $new_bonus;
                if (is_null($new_bonus)) {
                    $id = $this->setUsedAmount($student->id, $discount, $payment_id);
                    $new_bonus = StudentBonusPayment::find($id);
                }
            } else {
                $id = $this->setUsedAmount($student->id, $discount, $payment_id);
                $new_bonus = StudentBonusPayment::find($id);
            }
            //fallback to legacy
            if (is_null($id)) {
                $new_bonus = new StudentBonusPayment;
            }
            $new_bonus->student_id = $student->id;
            $new_bonus->amount = $discount * -1;
            $new_bonus->reason = $reason ?? ($scholarship ? env('APP_SCHOLARSHIP_REASON', 'Stipendijas atlaide maksājumam') : env('APP_BONUS_REASON', 'Atlaide bonus maksājumam'));
            if (!is_null($payment_id) and (strpos($new_bonus->reason, $payment_id) !== false)) {
                $new_bonus->reason .= " {$payment_id}";
            }
            $new_bonus->used = 0;
            $new_bonus->usable = 1;
            $new_bonus->scholarship_percent = $scholarship ? $discounts->scholarship->discount_amount : 0;
            $new_bonus->bonus_percent = $scholarship ? 0 : $discounts->bonus->discount_amount;
            $new_bonus->scholarship = $scholarship;
            $new_bonus->payment_id = $payment_id;
            if ($new_bonus->save()) {
                $id = $new_bonus->id;
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
        return $id;
    }

    private function setUsedAmount($userId, $debitAmount, ?string $payment_id = null): ?int
    {
        $debitId = null;
        try {
            DB::transaction(function () use ($userId, $debitAmount) {
                $debitLeft = abs($debitAmount);
                // Record the debit itself
                $debitId = DB::table('student_bonus_payments')->insertGetId([
                    'student_id' => $userId,
                    'amount' => -1 * abs($debitAmount),
                    'reason' => 'unsigned',
                    'used_amount' => 0,
                    'expires_at' => null,
                    'created_at' => now()
                ]);
                // Lock available credits for update, in fixed order
                $credits = DB::table('student_bonus_payments')
                    ->where('student_id', $userId)
                    ->where('amount', '>', 0)
                    ->whereRaw('amount > used_amount')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->orderByRaw('COALESCE(expires_at, "9999-12-31") ASC')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($credits as $credit) {
                    $available = $credit->amount - $credit->used_amount;
                    $use = min($available, $debitLeft);

                    DB::table('student_bonus_payments')
                        ->where('id', $credit->id)
                        ->update([
                            'used_amount' => DB::raw("used_amount + {$use}")
                        ]);

                    // (Optional) Track usage
                    DB::table('student_bonus_payments_usage')->insert([
                        'debit_id' =>  $debitId,
                        'credit_id' => $credit->id,
                        'amount_used' => $use,
                        'created_at' => now()
                    ]);

                    $debitLeft -= $use;

                    if ($debitLeft <= 0) {
                        break;
                    }
                }
            });
        } catch (\Exception $e) {
            Log::emergency("bonus payment setUsedAmount payment {$payment_id} :{$e->getMessage()}");
        }
        return $debitId;
    }

    public function makePromo(StudentBonusPayment $bonus): ?StudentPayment
    {
        $promo_payment = null;
        $payment = StudentPayment::where('id', $bonus->payment_id)->first();
        if ($payment) {
            //if need to show as promo
            $promo_payment = StudentPayment::where("promo", 1)->where('bonus_payment_id', $bonus->id)->first() ?? clone $payment->replicate();
            $promo_payment->paid = 1;
            $promo_payment->ts = now();
            $promo_payment->amount =  abs($bonus->amount);
            $promo_payment->reason = $bonus->reason;
            $promo_payment->promo = 1;
            $promo_payment->bonus_payment_id = $bonus->id;
            $promo_payment->save();
        }
        return $promo_payment;
    }

    public function getDiscount(?string $bonus_type = null, ?float $amount = null, ?float $bonus_amount = null)
    {
        $discount = 0;
        if (in_array($bonus_type, self::BONUS_TYPES)) {
            try {
                //discount magic begins
                $bonus = $this->getDiscounts($amount, $bonus_type);
                if (optional($bonus)->discount) {
                    if (optional($bonus->discount)->discount > 0) {
                        $max_discount =  optional($bonus)->discount->discount * 100;
                        $discount = ((($bonus_amount * 100) > $max_discount) ? optional($bonus)->discount->discounted_rate : $bonus_amount);
                    }
                }
            } catch (\Exception $e) {
                Log::error("StudentBonusDiscountHelper getPaymentDiscount {$e->getMessage()}");
            }
        }
        return $discount;
    }
}
