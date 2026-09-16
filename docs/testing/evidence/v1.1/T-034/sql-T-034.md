# T-034 金额分摊明细核对样例（sql-T-034）

**任务**：T-034 [BE] 用券校验与金额分摊 Service
**对象**：`App\Services\Marketing\PricingCalculator` / `AmountAllocator` / `CouponService`
**口径**：Backend_Design §3.4（本任务评审定稿，见下）

## 0. 规则定稿（代码 + 文档双记录）

| 项 | 规则 |
|----|------|
| 叠加顺序 | **先满减后券**（`PricingCalculator::STACK_ORDER = ['promotion','coupon']`） |
| 门槛判定 | 券 `min_spend` 与满减梯度均按**命中范围原始金额**（`scope=all/category/product`）判定 |
| 券优惠额 | fixed 直减 `amount`；percent 按 `base*(100-percent)/100` 并受 `max_discount` 封顶（percent=80 即 8 折） |
| 满减优惠额 | 按活动命中金额取**最优梯度**（同活动内取优惠最大档） |
| 叠加封顶 | 券优惠额再封顶至「满减后命中行可用余额」，避免与满减叠加后行实付为负 |
| 分摊 | 按行**可用余额**占比分摊，四舍五入到分；**尾差记入金额最大的行**（并列取索引最小） |
| 不变量 | Σ 行分摊 = 优惠总额；任一行实付 ≥ 0；应付 = 商品总额 − 券 − 满减 + 运费 ≥ 0 |
| 版本号 | `amount_details.v = 1` |

> 说明：分摊基准由「行原始金额」细化为「行可用余额」，等价于原始金额（首个优惠），
> 对第二个优惠改用扣减后余额，从而在「两种优惠命中同一行」时天然保证行实付 ≥ 0
> （见用例 TC-PRC-034-020）。

## 1. 样例 S1：多行按比例分摊 + 尾差归最大行

输入：三行金额 1 / 3 / 5 元（商品总额 9.00），全场固定券直减 4.00。

```
商品总额=9.00 满减=0.00 券=4.00 运费=0.00 应付=5.00
  行0 amount=1.00 promo_share=0.00 coupon_share=0.44 payable=0.56
  行1 amount=3.00 promo_share=0.00 coupon_share=1.33 payable=1.67
  行2 amount=5.00 promo_share=0.00 coupon_share=2.23 payable=2.77
```

手算核对：4×1/9=0.4444→0.44，4×3/9=1.3333→1.33，4×5/9=2.2222→2.22，Σ=3.99，
尾差 **+0.01 归金额最大的第 3 行** → 2.23，Σ=4.00 ✔；Σ 行实付=5.00=9.00−4.00 ✔

## 2. 样例 S2：分类券 + 满减叠加（先满减后券）

输入：行0 分类 10 金额 80.00，行1 分类 20 金额 40.00（商品总额 120.00）；
满减活动全场 `[100→10, 200→25]`；券为分类 10 的 8 折券（封顶 30）；运费 8.00。

```
商品总额=120.00 满减=10.00 券=16.00 运费=8.00 应付=102.00
  行0 amount=80.00 promo_share=6.67 coupon_share=16.00 payable=57.33
  行1 amount=40.00 promo_share=3.33 coupon_share=0.00 payable=36.67
```

手算核对：
- 满减：命中 120 ≥ 100 → 取 10；按金额占比分摊 80/120×10=6.67、40/120×10=3.33，Σ=10.00 ✔
- 券：命中范围仅分类 10（原始 80）→ 8 折优惠 = 80×20% = 16.00（未触顶 30）；封顶至满减后余额 80−6.67=73.33 → 16.00 ✔
- 行0 实付 = 80 − 6.67 − 16 = 57.33；行1 实付 = 40 − 3.33 = 36.67 ✔
- 应付 = 120 − 10 − 16 + 8 = 102.00 ✔

## 3. 不变量自检

`PricingCalculator::price()` 每次计算末尾调用 `assertInvariants()`，逐条断言：
`Σ coupon_share = coupon_discount`、`Σ promotion_share = promotion_discount`、
`Σ 行金额 = goods_amount`、`Σ 行实付 = goods − 优惠`、`pay_amount = goods − 优惠 + 运费 ≥ 0`、
无负数分摊、无负实付。任一破坏即抛 `LogicException`（快速失败，防止资损数据落库）。

## 4. 覆盖用例

单元 30 例（`tests/Unit/PricingCalculatorTest.php`）+ 集成 5 例
（`tests/Feature/CouponPricingServiceTest.php`），双库（SQLite / PG）全绿：
`pest-T-034.txt`、`pest-pgsql-T-034.txt`。
