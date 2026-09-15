<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductSku;
use App\Services\Inventory\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 购物车（API 文档 5）
 */
class CartController extends Controller
{
    use ApiResponse;

    public function __construct(private InventoryService $inventory)
    {
    }

    /** 购物车列表（含失效检测：商品下架 / SKU 禁用 / 库存不足 → valid=false） */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $items = CartItem::query()
            ->where('user_id', $userId)
            ->with(['sku:id,product_id,specs,price,status', 'sku.product:id,title,main_image,status'])
            ->orderByDesc('id')
            ->get();

        $stockMap = $this->inventory->getStockMap($items->pluck('sku_id')->all());

        $list = [];
        $totalAmount = '0';
        $totalQuantity = 0;

        foreach ($items as $item) {
            $sku = $item->sku;
            $product = $sku?->product;
            $stock = $stockMap[$item->sku_id] ?? 0;

            $valid = $product !== null
                && (int) $product->status === 1
                && $sku !== null
                && (int) $sku->status === 1
                && $stock >= $item->quantity;

            $price = $sku?->price ?? '0';
            $subtotal = bcmul((string) $price, (string) $item->quantity, 2);

            if ($valid) {
                $totalAmount = bcadd($totalAmount, $subtotal, 2);
                $totalQuantity += $item->quantity;
            }

            $list[] = [
                'id' => $item->id,
                'sku_id' => $item->sku_id,
                'product_id' => $sku?->product_id,
                'title' => $product?->title ?? '商品已删除',
                'specs' => $sku?->specs ?? [],
                'image' => $product?->main_image,
                'price' => $price,
                'quantity' => $item->quantity,
                'stock' => $stock,
                'valid' => $valid,
                'invalid_reason' => $this->invalidReason($product, $sku, $stock),
                'subtotal' => $subtotal,
            ];
        }

        return $this->success([
            'items' => $list,
            'total_amount' => $totalAmount,
            'total_quantity' => $totalQuantity,
        ]);
    }

    /** 加入购物车（同 SKU 合并数量；库存不足 40009） */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $sku = ProductSku::with('product:id,status')->find($data['sku_id']);
        if (! $sku || (int) $sku->product->status !== 1) {
            throw BusinessException::notFound('商品不存在或已下架');
        }

        $userId = $request->user()->id;
        $existing = CartItem::where('user_id', $userId)->where('sku_id', $sku->id)->first();
        $targetQty = ($existing?->quantity ?? 0) + $data['quantity'];

        if (! $this->inventory->isSufficient($sku->id, $targetQty)) {
            throw BusinessException::conflict('库存不足');
        }

        DB::transaction(function () use ($userId, $sku, $data, $existing, $targetQty) {
            if ($existing) {
                $existing->quantity = $targetQty;
                $existing->save();
            } else {
                CartItem::create([
                    'user_id' => $userId,
                    'sku_id' => $sku->id,
                    'quantity' => $data['quantity'],
                ]);
            }
        });

        return $this->success(null, '已加入购物车');
    }

    /** 修改数量 */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $item = CartItem::where('user_id', $request->user()->id)->find($id);
        if (! $item) {
            throw BusinessException::notFound('购物车项不存在');
        }

        if (! $this->inventory->isSufficient($item->sku_id, $data['quantity'])) {
            throw BusinessException::conflict('库存不足');
        }

        $item->quantity = $data['quantity'];
        $item->save();

        return $this->success(null, '已更新');
    }

    /** 删除购物车项 */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = CartItem::where('user_id', $request->user()->id)->where('id', $id)->delete();
        if (! $deleted) {
            throw BusinessException::notFound('购物车项不存在');
        }

        return $this->success(null, '已删除');
    }

    /** 清空购物车 */
    public function clear(Request $request): JsonResponse
    {
        CartItem::where('user_id', $request->user()->id)->delete();

        return $this->success(null, '已清空');
    }

    /** 购物车角标数量（有效项） */
    public function count(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $count = CartItem::query()
            ->where('user_id', $userId)
            ->whereHas('sku.product', fn ($q) => $q->where('status', 1))
            ->whereHas('sku', fn ($q) => $q->where('status', 1))
            ->sum('quantity');

        return $this->success(['count' => (int) $count]);
    }

    private function invalidReason(?Product $product, ?ProductSku $sku, int $stock): ?string
    {
        if ($product === null) {
            return '商品已删除';
        }
        if ((int) $product->status !== 1) {
            return '商品已下架';
        }
        if ($sku === null || (int) $sku->status !== 1) {
            return '规格已失效';
        }
        if ($stock <= 0) {
            return '已售罄';
        }
        if ($stock < 1) {
            return '库存不足';
        }

        return null;
    }
}
