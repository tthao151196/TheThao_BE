<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AIChatController extends Controller
{
    public function chat(Request $req)
    {
        try {
            $userMsg = (string) $req->input('message', '');
            if (trim($userMsg) === '') {
                return response()->json(['reply' => ''], 200);
            }

            $msgNorm = $this->norm($userMsg);

            /* ===== 0) Small talk / shop / danh mục & SP mới ===== */
            if ($this->looksLikeGreeting($msgNorm)) {
                return response()->json(['reply' => $this->answerGreeting()], 200);
            }
            if ($this->looksLikeShopName($msgNorm)) {
                return response()->json(['reply' => $this->getShopName()], 200);
            }
            if ($this->looksLikeWhatProducts($msgNorm)) {
                return response()->json(['reply' => $this->answerWhatProducts()], 200);
            }

            /* ===== 1) Thời gian ===== */
            if ($this->looksLikeTimeIntent($msgNorm)) {
                return response()->json(['reply' => $this->answerDatetime()], 200);
            }

            /* ===== 2) Thời tiết (nếu bạn cấu hình OPENWEATHER_KEY) ===== */
            if ($this->looksLikeWeatherIntent($msgNorm)) {
                $city = $this->extractCity($userMsg) ?: env('OPENWEATHER_DEFAULT_CITY', 'Ho Chi Minh');
                return response()->json(['reply' => $this->answerWeather($city)], 200);
            }

            /* ===== 3) Hỏi giá theo tên SP ===== */
            if ($this->looksLikeProductPriceByName($msgNorm)) {
                $name = $this->extractProductNameFromQuestion($userMsg) ?? '';
                if ($name !== '') {
                    $ans = $this->answerProductPriceByName($name);
                    if ($ans) return response()->json(['reply' => $ans], 200);
                }
            }

            /* ===== 4) Thống kê giá min/max/avg ===== */
            if ($this->looksLikePriceStatsIntent($msgNorm)) {
                $stats = $this->queryProductPriceStats();
                if (!$stats) return response()->json(['reply' => 'Chưa có dữ liệu giá.'], 200);
                $fmt = fn($v) => number_format((float)$v, 0, ',', '.') . 'đ';
                $reply = "SP: {$stats['total']} | Thấp nhất: ".$fmt($stats['min_price'])." | Cao nhất: ".$fmt($stats['max_price'])." | TB: ".$fmt($stats['avg_price']);
                return response()->json(['reply' => $reply], 200);
            }

            /* ===== 5) Sản phẩm đang giảm giá ===== */
            if ($this->looksLikeOnSaleIntent($msgNorm)) {
                $cards = $this->queryOnSaleProducts(12);
                if (!$cards) return response()->json(['reply' => 'Chưa có sản phẩm giảm giá.'], 200);
                return response()->json(['reply' => 'Sản phẩm đang giảm giá', 'title' => 'Sản phẩm đang giảm giá', 'cards' => $cards], 200);
            }

            /* ===== 6) Bán chạy ===== */
            if ($this->looksLikeBestSellerIntent($msgNorm)) {
                $days = $this->parseDaysWindow($msgNorm) ?? 90;
                $best = $this->queryBestSellers(6, $days);
                if (!$best) return response()->json(['reply' => 'Chưa có dữ liệu bán chạy.'], 200);
                $cards = array_map(fn($r) => $r['card'], $best);
                $title = "Top bán chạy $days ngày";
                return response()->json(['reply' => $title, 'title' => $title, 'cards' => $cards], 200);
            }

            /* ===== 7) Tìm theo từ khóa / giới tính / danh mục / khoảng giá ===== */
            if (
                $this->looksLikeSuggestIntent($msgNorm) ||
                $this->looksLikeSearchIntent($msgNorm)  ||
                $this->looksLikeWomenIntent($msgNorm)   ||
                $this->looksLikeMenIntent($msgNorm)     ||
                $this->looksLikeCategoryIntent($msgNorm)||
                $this->looksLikePriceNumberIntent($msgNorm)
            ) {
                $gender  = $this->looksLikeWomenIntent($msgNorm) ? 'female' : ($this->looksLikeMenIntent($msgNorm) ? 'male' : null);
                $kw      = $this->extractKeyword($userMsg);
                $catSlug = $this->extractCategorySlug($userMsg);
                $price   = $this->extractPriceFromText($userMsg);   // 199k, 199.000, 199000

                // Cho phép kết hợp: keyword + gender + category + khoảng giá
                $cards = $this->querySearchSmart(
                    limit: 12,
                    gender: $gender,
                    keyword: $kw,
                    categorySlug: $catSlug,
                    priceAround: $price,
                    priceToleranceRate: 0.15 // ±15%
                );

                if (!$cards) {
                    // fallback: on-sale hoặc newest
                    $fallback = $this->queryOnSaleProducts(12);
                    if (!$fallback) $fallback = array_map(fn($r) => $r['card'], $this->queryNewestProducts(12));
                    $cards = $fallback;
                }

                $titleParts = [];
                if ($gender === 'female') $titleParts[] = 'cho nữ';
                if ($gender === 'male')   $titleParts[] = 'cho nam';
                if ($kw)                  $titleParts[] = "từ khóa “$kw”";
                if ($catSlug)             $titleParts[] = "danh mục “$catSlug”";
                if ($price)               $titleParts[] = "quanh ".number_format($price,0,',','.')."đ";
                $title = 'Gợi ý '.($titleParts ? '('.implode(' • ', $titleParts).')' : 'sản phẩm');

                return response()->json(['reply' => $title, 'title' => $title, 'cards' => $cards], 200);
            }

            /* ===== 8) Fallback: LLM cho câu ngoài lề ===== */
            if ($txt = $this->askLLM($userMsg)) {
                return response()->json(['reply' => $txt], 200);
            }

            /* ===== 9) fallback ngắn gọn ===== */
            return response()->json(['reply' => $this->answerFallback($userMsg)], 200);

        } catch (\Throwable $e) {
            Log::error('AI /api/ai/chat failed', [
                'err'   => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);

            if (config('app.debug')) {
                return response()->json([
                    'reply' => '',
                    'error' => $e->getMessage(),
                    'at'    => basename($e->getFile()) . ':' . $e->getLine(),
                ], 500);
            }
            return response()->json(['reply' => ''], 200);
        }
    }

    /* ================== STRICT MODE ================== */
    private function isStrict(): bool { return (bool) config('aichat.strict_mode', true); }

    /* ================== CHUẨN HÓA ================== */
    private function norm(string $s): string
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $find = ['á','à','ả','ã','ạ','ă','ắ','ằ','ẳ','ẵ','ặ','â','ấ','ầ','ẩ','ẫ','ậ','đ','é','è','ẻ','ẽ','ẹ','ê','ế','ề','ể','ễ','ệ','í','ì','ỉ','ĩ','ị','ó','ò','ỏ','õ','ọ','ô','ố','ồ','ổ','ỗ','ộ','ơ','ớ','ờ','ở','ỡ','ợ','ú','ù','ủ','ũ','ụ','ư','ứ','ừ','ử','ữ','ự','ý','ỳ','ỷ','ỹ','ỵ'];
        $rep  = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','d','e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y'];
        $s = str_replace($find, $rep, $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
    private function ncontains(string $hay, array $needles): bool
    {
        $h = $this->norm($hay);
        foreach ($needles as $n) if (Str::contains($h, $this->norm($n))) return true;
        return false;
    }

    /* ================== INTENTS ================== */
    private function looksLikeGreeting(string $q): bool {
        return (bool) preg_match('/\b(xin chao|chao|hello|alo|hi|helo|heloo|hey)\b/u', $this->norm($q));
    }
    private function looksLikeShopName(string $q): bool {
        return $this->ncontains($q, ['shop bạn tên gì','ten shop','ten cua hang','shop ten gi','ten ben ban','shop ten la gi']);
    }
    private function looksLikeWhatProducts(string $q): bool {
        if ($this->ncontains($q, ['shop nhung san pham nao','shop co nhung san pham nao','nhung san pham nao',
            'san pham nao','danh sach san pham','san pham hien co','shop co gi','co gi ban','danh muc nao','category nao'])) return true;
        $n = $this->norm($q);
        return (bool)(preg_match('/\bsan pham\b/u', $n) && preg_match('/\b(nao|gi)\b/u', $n));
    }
    private function looksLikeTimeIntent(string $q): bool {
        return $this->ncontains($q, ['may gio','gio hien tai','bay gio la may gio','hom nay la ngay may','ngay thang','thoi gian bay gio','gio bay gio','ngay bay gio','nay thu may','hom nay thu may','thu may']);
    }
    private function looksLikeWeatherIntent(string $q): bool {
        return $this->ncontains($q, ['thoi tiet','thoi tiet tai','troi mua khong','troi co mua khong','nhiet do']);
    }
    private function looksLikeProductPriceByName(string $q): bool {
        $n = $this->norm($q);
        return (bool)(preg_match('/\bgia|bao nhieu tien|gia bao nhieu|la bao nhieu|bn\b/u', $n));
    }
    private function looksLikePriceStatsIntent(string $q): bool {
        $hasKey = $this->ncontains($q, ['gia','gia ban','gia thanh','price']);
        $type   = $this->ncontains($q, ['cao nhat','dat nhat','max','thap nhat','re nhat','min','trung binh','average','avg']);
        return $hasKey && $type;
    }
    private function looksLikeOnSaleIntent(string $q): bool {
        return $this->ncontains($q, ['giam gia','dang giam gia','khuyen mai','sale','dang sale']);
    }
    private function looksLikeBestSellerIntent(string $q): bool {
        return $this->ncontains($q, ['ban chay','best seller','bestseller','top ban','nhieu don','nhieu luot mua','mua nhieu']);
    }
    private function looksLikeSuggestIntent(string $q): bool {
        return $this->ncontains($q, ['goi y','goi y san pham','de xuat','recommend','suggest','nen mua','phu hop','tu van']);
    }
    private function looksLikeSearchIntent(string $q): bool {
        return $this->ncontains($q, ['tim','tim san pham','co san pham']) ||
               (bool)preg_match('/\b(ao|quan|giay|dep|vot|bong|hoodie|legging|short|jersey|do the thao)\b/u', $this->norm($q));
    }
    private function looksLikeWomenIntent(string $q): bool {
        return $this->ncontains($q, ['cho nu','phu nu','nu gioi','women','ladies','con gai','nu']);
    }
    private function looksLikeMenIntent(string $q): bool {
        return $this->ncontains($q, ['cho nam','nam gioi','men','anh em']);
    }
    private function looksLikeCategoryIntent(string $q): bool {
        return $this->ncontains($q, ['danh muc','category','muc nao','bo mon','mon nao']);
    }
    private function looksLikePriceNumberIntent(string $q): bool {
        return (bool) $this->extractPriceFromText($q);
    }

    /* ================== THỜI TIẾT ================== */
    private function extractCity(string $q): ?string
    {
        $nq = $this->norm($q);
        $map = [
            'ho chi minh' => ['hcm','tp hcm','tphcm','sai gon','ho chi minh'],
            'ha noi'      => ['ha noi','hanoi','hn'],
            'da nang'     => ['da nang','danang'],
            'can tho'     => ['can tho','cantho'],
            'hai phong'   => ['hai phong','haiphong'],
        ];
        foreach ($map as $city => $aliases) {
            foreach ($aliases as $a) if (Str::contains($nq, $a)) return $city;
        }
        return null;
    }
    private function answerWeather(string $city): string
    {
        $apiKey = env('OPENWEATHER_KEY');
        if (!$apiKey) return "Chưa cấu hình khóa thời tiết. Thêm OPENWEATHER_KEY vào .env để tra thời tiết.";
        try {
            $resp = Http::timeout(10)->get('https://api.openweathermap.org/data/2.5/weather', [
                'q'     => $city.',VN',
                'appid' => $apiKey,
                'units' => 'metric',
                'lang'  => 'vi',
            ]);
            if (!$resp->successful()) {
                Log::warning('weather fail', ['status'=>$resp->status(),'body'=>$resp->body()]);
                return "Xin lỗi, không lấy được thời tiết cho $city lúc này.";
            }
            $d = $resp->json();
            $temp = round($d['main']['temp'] ?? 0);
            $feels = round($d['main']['feels_like'] ?? $temp);
            $desc = $d['weather'][0]['description'] ?? '';
            $hum = $d['main']['humidity'] ?? null;
            $wind = $d['wind']['speed'] ?? null;

            $parts = ["Thời tiết $city: $desc, $temp°C (cảm giác $feels°C)"];
            if ($hum !== null)  $parts[] = "Độ ẩm $hum%";
            if ($wind !== null) $parts[] = "Gió $wind m/s";
            return implode(' | ', $parts);

        } catch (\Throwable $e) {
            Log::warning('weather ex', ['err'=>$e->getMessage()]);
            return "Xin lỗi, không truy cập được dịch vụ thời tiết.";
        }
    }

    /* ================== SHOP / DANH MỤC / MỚI NHẤT ================== */
    private function getShopName(): string { return (string) config('aichat.shop_name', 'THETHAO SPORTS'); }
    private function answerGreeting(): string { return $this->isStrict() ? 'Chào bạn.' : "Chào bạn! Mình là trợ lý của ".$this->getShopName()."."; }

    private function answerWhatProducts(): string
    {
        $catLines = [];
        if (Schema::hasTable('ptdt_category') && Schema::hasColumn('ptdt_category', 'name')) {
            $cats = DB::table('ptdt_category')->select('id','name','slug')->orderBy('id','desc')->limit(10)->get();
            foreach ($cats as $c) {
                $u = $this->buildCategoryUrl($c->slug);
                $catLines[] = $u ? ($c->name.' → '.$u) : $c->name;
            }
        }
        $prdLines = [];
        foreach ($this->queryNewestProducts(10) as $p) {
            $price = $p['price'] !== null ? number_format((float)$p['price'], 0, ',', '.') . 'đ' : '—';
            $line  = $p['name'].' ('.$price.')';
            if ($p['url']) $line .= ' → '.$p['url'];
            $prdLines[] = $line;
        }
        if ($this->isStrict()) {
            $parts = [];
            if ($catLines) $parts[] = 'Danh mục: '.implode(' | ', $catLines);
            if ($prdLines) $parts[] = 'SP mới: '.implode(' | ', $prdLines);
            return $parts ? implode(' | ', $parts) : 'Chưa có dữ liệu.';
        }
        $out = [];
        if ($catLines) $out[] = "Danh mục:\n- ".implode("\n- ", $catLines);
        if ($prdLines) $out[] = "Sản phẩm mới:\n- ".implode("\n- ", $prdLines);
        return $out ? implode("\n\n", $out) : 'Chưa có dữ liệu.';
    }

    /* ================== THỜI GIAN ================== */
    private function answerDatetime(): string
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $weekdayMap = [0=>'Chủ nhật',1=>'Thứ hai',2=>'Thứ ba',3=>'Thứ tư',4=>'Thứ năm',5=>'Thứ sáu',6=>'Thứ bảy'];
        $thu = $weekdayMap[(int)$now->dayOfWeek];
        $open  = config('aichat.shop_open');
        $close = config('aichat.shop_close');
        $base  = "{$thu}, {$now->format('d/m/Y')} — {$now->format('H:i')}";
        if ($this->isStrict()) return $base;
        if ($open && $close) {
            $nowH = $now->format('H:i');
            $in = ($nowH >= $open && $nowH <= $close) ? 'trong' : 'ngoài';
            return $base." (đang {$in} giờ làm việc {$open}–{$close})";
        }
        return $base;
    }

    /* ================== GIÁ / DB helpers ================== */
    private function getPriceColumns(): array { return [config('aichat.product_price_col'), config('aichat.product_sale_price_col')]; }
    private function buildPriceExpr(array $cols): array
    {
        [$envPrice, $envSale] = $this->getPriceColumns();
        $priceCol = null; $saleCol  = null;
        if ($envPrice && in_array($envPrice, $cols)) $priceCol = $envPrice;
        if ($envSale  && in_array($envSale,  $cols)) $saleCol  = $envSale;
        if (!$priceCol) foreach (['price_root','price'] as $c) if (in_array($c, $cols)) { $priceCol = $c; break; }
        if (!$saleCol && in_array('price_sale', $cols)) $saleCol = 'price_sale';
        if (!$priceCol && !$saleCol) return [null, null, null];
        $expr = $saleCol ? "CASE WHEN {$saleCol} IS NOT NULL AND {$saleCol} > 0 THEN {$saleCol} ELSE ".($priceCol ?: $saleCol)." END" : ($priceCol ?? $saleCol);
        return [$expr, $priceCol, $saleCol];
    }

    private function queryProductPriceStats(): ?array
    {
        $t = 'ptdt_product';
        if (!Schema::hasTable($t)) return null;
        [$expr] = $this->buildPriceExpr(Schema::getColumnListing($t));
        if (!$expr) return null;
        $r = DB::table($t)->selectRaw("COUNT(*) total, MIN($expr) min_price, MAX($expr) max_price, AVG($expr) avg_price")->first();
        if (!$r || !$r->total) return null;
        return ['total'=>(int)$r->total,'min_price'=>(float)$r->min_price,'max_price'=>(float)$r->max_price,'avg_price'=>(float)$r->avg_price];
    }

    /* ================== TRẢ LỜI GIÁ THEO TÊN SP ================== */
    private function extractProductNameFromQuestion(string $q): ?string
    {
        $orig = trim($q); $n = $this->norm($orig);
        if (preg_match('/^(.*)\b(gia|bao nhieu tien|gia bao nhieu|la bao nhieu|bn)\b/u', $n, $m)) {
            $cand = trim($m[1]); if ($cand !== '') return $cand;
        }
        if (preg_match('/\b(gia(?: cua)?)\s+(.*)\b/u', $n, $m)) {
            $cand = trim($m[2]); if ($cand !== '') return $cand;
        }
        return null;
    }
    private function answerProductPriceByName(string $name): ?string
    {
        $t = 'ptdt_product'; if (!Schema::hasTable($t) || $name === '') return null;
        [$expr] = $this->buildPriceExpr(Schema::getColumnListing($t)); $expr = $expr ?: 'NULL';
        $rows = DB::table($t)->select('id','name','slug','thumbnail')->selectRaw("$expr as price")
                 ->where('name', 'like', '%'.$name.'%')->orderByDesc('id')->limit(8)->get();
        if ($rows->isEmpty()) return null;
        $fmt = fn($v) => $v === null ? '—' : (number_format((float)$v, 0, ',', '.') . 'đ');
        $lines = [];
        foreach ($rows as $r) {
            $p = $r->price !== null ? (float)$r->price : null;
            $u = $this->buildProductUrl($r->slug);
            $line = $r->name.' ('.$fmt($p).')'; if ($u) $line .= ' → '.$u;
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /* ================== TÌM KIẾM / LỌC SP TOÀN DIỆN ================== */
    private function queryOnSaleProducts(int $limit = 12): array
    {
        $p='ptdt_product'; if (!Schema::hasTable($p)) return [];
        [$expr, $priceCol, $saleCol] = $this->buildPriceExpr(Schema::getColumnListing($p));
        $expr = $expr ?: ($priceCol ?: $saleCol ?: 'NULL');

        $q = DB::table($p)->select('id','name','slug','thumbnail')->selectRaw("$expr as price");
        if ($saleCol) $q->where($saleCol,'>',0); else return [];

        $rows = $q->orderByDesc('id')->limit($limit)->get();
        return $rows->map(fn($r) => $this->toCard($r->name, $r->slug, $r->thumbnail, $r->price))->toArray();
    }

    private function querySearchSmart(
        int $limit = 12,
        ?string $gender = null,
        ?string $keyword = null,
        ?string $categorySlug = null,
        ?int $priceAround = null,
        float $priceToleranceRate = 0.15
    ): array {
        $p='ptdt_product'; if (!Schema::hasTable($p)) return [];
        [$expr] = $this->buildPriceExpr(Schema::getColumnListing($p)); $expr = $expr ?: 'NULL';

        $q = DB::table("$p as pr")->select('pr.id','pr.name','pr.slug','pr.thumbnail')->selectRaw("$expr as price");

        // join category nếu có
        $hasCat = Schema::hasTable('ptdt_category') && Schema::hasColumn('ptdt_category','id');
        $hasPC  = Schema::hasTable('ptdt_product_category') && Schema::hasColumn('ptdt_product_category','product_id');
        if ($hasCat && $hasPC) {
            $q->leftJoin('ptdt_product_category as pc', 'pc.product_id', '=', 'pr.id')
              ->leftJoin('ptdt_category as c', 'c.id', '=', 'pc.category_id');
        }

        // gender
        $cols = Schema::getColumnListing($p);
        if ($gender && in_array('gender', $cols)) {
            if ($gender === 'female') $q->whereIn('pr.gender', ['female','women','ladies','nu']);
            if ($gender === 'male')   $q->whereIn('pr.gender', ['male','men','nam']);
        }

        // keyword
        if ($keyword) {
            $kw = '%'.$keyword.'%';
            $q->where(function($w) use ($kw) {
                $w->where('pr.name','like',$kw)
                  ->orWhere('pr.slug','like',$kw);
            });
        }

        // category
        if ($categorySlug && $hasCat && $hasPC) {
            $q->where(function($w) use ($categorySlug) {
                $w->where('c.slug', $categorySlug)->orWhere('c.name','like','%'.$categorySlug.'%');
            });
        }

        // price around ±%
        if ($priceAround !== null) {
            $min = max(0, (int) round($priceAround*(1-$priceToleranceRate)));
            $max = (int) round($priceAround*(1+$priceToleranceRate));
            $q->whereBetween(DB::raw($expr), [$min, $max]);
        }

        $rows = $q->orderByDesc('pr.id')->limit($limit)->get();

        return $rows->map(fn($r) => $this->toCard($r->name, $r->slug, $r->thumbnail, $r->price))->toArray();
    }

    private function querySuggestedProducts(int $limit = 6): array
    {
        $p='ptdt_product'; if (!Schema::hasTable($p)) return [];
        [$priceExpr, $priceCol, $saleCol] = $this->buildPriceExpr(Schema::getColumnListing($p));
        $priceExpr = $priceExpr ?: ($priceCol ?: $saleCol ?: 'NULL');

        // on-sale trước
        $saleQ = DB::table($p)->select('id','name','slug','thumbnail')->selectRaw("$priceExpr as price");
        if ($saleCol) $saleQ->where($saleCol,'>',0); else $saleQ->whereRaw('1=0');
        $saleQ->orderByDesc('id')->limit($limit);
        $items = $saleQ->get()->map(fn($r) => $this->toCard($r->name, $r->slug, $r->thumbnail, $r->price))->toArray();

        if (count($items) < $limit) {
            $need = $limit - count($items);
            $ids  = [];
            $newQ = DB::table($p)->select('id','name','slug','thumbnail')->selectRaw("$priceExpr as price")->orderByDesc('id')->limit($need);
            if ($ids) $newQ->whereNotIn('id', $ids);
            $more  = $newQ->get()->map(fn($r) => $this->toCard($r->name, $r->slug, $r->thumbnail, $r->price))->toArray();
            $items = array_merge($items, $more);
        }
        return array_map(fn($c) => ['card'=>$c], $items); // giữ format cũ nếu cần
    }

    private function queryNewestProducts(int $limit = 10): array
    {
        $p='ptdt_product';
        if (!Schema::hasTable($p)) return [];
        [$priceExpr, $priceCol, $saleCol] = $this->buildPriceExpr(Schema::getColumnListing($p));
        $priceExpr = $priceExpr ?: ($priceCol ?: $saleCol ?: 'NULL');

        $rows = DB::table($p)->select('id','name','slug','thumbnail')->selectRaw("$priceExpr as price")->orderByDesc('id')->limit($limit)->get();

        return $rows->map(function($r){
            $img = $this->buildImageUrl($r->thumbnail);
            $url = $this->buildProductUrl($r->slug);
            $price = $r->price !== null ? (float)$r->price : null;
            return [
                'id'=>(int)$r->id,'name'=>$r->name,'slug'=>$r->slug,'price'=>$price,'url'=>$url,'image'=>$img,
                'card'=>[
                    'title'=>$r->name,
                    'subtitle'=>$price!==null?number_format($price,0,',','.').'đ':null,
                    'image'=>$img,'url'=>$url
                ],
            ];
        })->toArray();
    }

    private function getDoneStatusCodes(): array
    {
        // .env: ORDER_STATUS_DONE=4 hoặc "4,6"
        $conf = config('aichat.order_status_done', [4]);
        if (is_string($conf)) $conf = array_map('intval', explode(',', $conf));
        return array_map('intval', (array) $conf);
    }

    private function queryBestSellers(int $limit = 6, ?int $days = 90): array
    {
        $od='ptdt_orderdetail'; $o='ptdt_order'; $p='ptdt_product';
        if (!Schema::hasTable($od) || !Schema::hasTable($o) || !Schema::hasTable($p)) return [];
        $done = $this->getDoneStatusCodes();

        $q = DB::table("$od as od")
            ->join("$o as o", 'o.id', '=', 'od.order_id')
            ->join("$p as pr",'pr.id','=','od.product_id')
            ->whereIn('o.status', $done);

        if ($days && $days > 0) $q->where('o.created_at','>=', Carbon::now()->subDays($days));

        [$priceExpr] = $this->buildPriceExpr(Schema::getColumnListing($p));
        $priceExpr = $priceExpr ?: 'NULL';

        $rows = $q->groupBy('od.product_id','pr.name','pr.slug','pr.thumbnail')
            ->selectRaw("
                od.product_id, pr.name, pr.slug, pr.thumbnail,
                SUM(od.qty) total_qty, SUM(od.amount) total_amount,
                $priceExpr as current_price
            ")
            ->orderByDesc('total_qty')
            ->limit($limit)->get();

        return $rows->map(function ($r) {
            $price = $r->current_price !== null ? (float)$r->current_price : null;
            $url   = $this->buildProductUrl($r->slug);
            $img   = $this->buildImageUrl($r->thumbnail);
            return [
                'product_id'    => (int)$r->product_id,
                'name'          => $r->name,
                'slug'          => $r->slug,
                'image'         => $img,
                'total_qty'     => (int)$r->total_qty,
                'total_amount'  => (float)$r->total_amount,
                'current_price' => $price,
                'url'           => $url,
                'card'          => [
                    'title'    => $r->name,
                    'subtitle' => $price !== null ? number_format($price, 0, ',', '.') . 'đ' : null,
                    'image'    => $img,
                    'url'      => $url,
                ],
            ];
        })->toArray();
    }

    /* ================== URL & ẢNH ================== */
    private function toCard(?string $name, ?string $slug, ?string $thumb, $price): array
    {
        $img = $this->buildImageUrl($thumb);
        $url = $this->buildProductUrl($slug);
        $subtitle = ($price!==null) ? number_format((float)$price,0,',','.').'đ' : null;
        return ['title'=>$name ?? 'Sản phẩm','subtitle'=>$subtitle,'image'=>$img,'url'=>$url];
    }

    private function buildProductUrl(?string $slug): ?string
    {
        if (!$slug) return null;
        $origin = (string) config('aichat.frontend_origin', '');
        $tpl    = (string) config('aichat.frontend_product_path', '/san-pham/{slug}');
        $path   = str_replace('{slug}', $slug, $tpl);
        if ($origin === '') return $path;
        if ($path && $path[0] !== '/') $path = '/'.$path;
        return rtrim($origin, '/') . $path;
    }
    private function buildCategoryUrl(?string $slug): ?string
    {
        if (!$slug) return null;
        $origin = (string) config('aichat.frontend_origin', '');
        $tpl    = (string) config('aichat.frontend_category_path', '/danh-muc/{slug}');
        $path   = str_replace('{slug}', $slug, $tpl);
        if ($origin === '') return $path;
        if ($path && $path[0] !== '/') $path = '/'.$path;
        return rtrim($origin, '/') . $path;
    }
    private function buildImageUrl(?string $path): ?string
    {
        if (!$path || !is_string($path)) return null;
        if (Str::startsWith($path, ['http://','https://'])) return $path;
        $origin = (string) config('aichat.asset_origin', '');
        $path = ltrim($path, '/');
        return $origin === '' ? '/'.$path : (rtrim($origin,'/').'/'.$path);
    }

    /* ================== PARSER & FALLBACK ================== */
    private function parseDaysWindow(string $q): ?int
    {
        if (preg_match('/(\d+)\s*(ngay|d)\b/u', $q, $m)) return max(1, (int)$m[1]);
        if (preg_match('/(\d+)\s*(thang|m)\b/u', $q, $m)) return max(1, (int)$m[1]*30);
        if (preg_match('/trong\s+(\d+)\b/u', $q, $m) || preg_match('/(\d+)\s+gan\s+day/u', $q, $m)) return max(1, (int)$m[1]);
        return null;
    }
    private function extractKeyword(string $q): ?string
    {
        $n = $this->norm($q);
        if (preg_match('/tim (?:san pham )?(.+)/u', $n, $m)) return trim($m[1]);
        if (preg_match('/san pham (.+)/u', $n, $m)) return trim($m[1]);
        if (preg_match('/(ao|quan|giay|dep|vot|bong|hoodie|legging|short|ao thun|ao khoac|jersey|do the thao).*/u', $n, $m))
            return trim($m[0]);
        return null;
    }
    private function extractCategorySlug(string $q): ?string
    {
        // Nếu người dùng gõ "danh mục cau-long" hoặc "category bong-ro"
        $n = $this->norm($q);
        if (preg_match('/danh muc\s+([a-z0-9\-]+)/u', $n, $m)) return $m[1];
        if (preg_match('/category\s+([a-z0-9\-]+)/u', $n, $m)) return $m[1];
        return null;
    }
    private function extractPriceFromText(string $q): ?int
    {
        // bắt số: "199k", "199.000", "199,000", "199000", "199.000 đ"
        $n = $this->norm($q);
        if (preg_match('/(\d[\d\.\,]*)\s*(k|ngan|nghìn|nghin)?/u', $n, $m)) {
            $num = (int) str_replace([',','.'],'',$m[1]);
            if ($num <= 0) return null;
            $unit = isset($m[2]) ? trim($m[2]) : '';
            if (in_array($unit, ['k','ngan','nghìn','nghin'])) $num *= 1000;
            return $num;
        }
        return null;
    }
    private function answerFallback(string $userMsg): string
    {
        return $this->isStrict() ? 'Mình chưa hiểu câu này.' : "Mình chưa hiểu rõ câu này.";
    }

    /* ================== LLM Fallback ================== */
    private function askLLM(string $userMsg): ?string
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) return null;

        $model  = env('OPENAI_MODEL', 'gpt-4o-mini');
        $shop   = (string) config('aichat.shop_name', 'Cửa hàng');
        $open   = (string) (config('aichat.shop_open') ?: '');
        $close  = (string) (config('aichat.shop_close') ?: '');

        $system = "Bạn là trợ lý AI tiếng Việt, lịch sự, xúc tích, có thể trả lời câu hỏi tổng quát. ".
                  "Ưu tiên dữ liệu nội bộ (tên shop {$shop}".($open&&$close? ", giờ {$open}-{$close}" : "")."). ".
                  "Nếu người dùng hỏi về sản phẩm nhưng DB không có chi tiết, hãy trả lời chung và không bịa link.";

        try {
            $resp = Http::withToken($apiKey)->timeout(15)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $userMsg],
                    ],
                    'temperature' => 0.3,
                    'max_tokens'  => 320,
                ]);
            if (!$resp->successful()) {
                Log::warning('askLLM http fail', ['status'=>$resp->status(),'body'=>$resp->body()]);
                return null;
            }
            $data = $resp->json();
            return $data['choices'][0]['message']['content'] ?? null;

        } catch (\Throwable $e) {
            Log::warning('askLLM exception', ['err'=>$e->getMessage()]);
            return null;
        }
    }
}
