<?php
/**
 * 2018-2020 Alma SAS
 *
 * THE MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the "Software"), to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and
 * to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the
 * Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 * WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
 * CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @author    Alma SAS <contact@getalma.eu>
 * @copyright 2018-2020 Alma SAS
 * @license   https://opensource.org/licenses/MIT The MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

include_once _PS_MODULE_DIR_ . 'alma/includes/AlmaLogger.php';
include_once _PS_MODULE_DIR_ . 'alma/includes/functions.php';

class CartData
{
    /**
     * @param Cart $cart
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public static function cartInfo($cart) {
        $items = array();

        $productDetails = array();
        if (version_compare(_PS_VERSION_, '1.7', '>=')) {
            $products = $cart->getProductsWithSeparatedGifts();
        } else {
            $products = $cart->getProducts(true);
            $productDetails = self::getProductsDetails($products);
        }

        $combinationsNames = self::getProductsCombinations($cart, $products);

        foreach ($products as $idx => $productRow) {
            $product = new Product(null, false, $cart->id_lang);
            $product->hydrate($productRow);

            $pid = (int)$product->id;
            $link = Context::getContext()->link;

            $data = array(
                'sku' => $productRow['reference'],
                'vendor' => isset($productRow['manufacturer_name']) ? $productRow['manufacturer_name'] : $productDetails[$pid]['manufacturer_name'],
                'title' => $productRow['name'],
                'variant_title' => null,
                'quantity' => $productRow['cart_quantity'],
                'unit_price' => almaPriceToCents($productRow['price_wt']),
                'line_price' => almaPriceToCents($productRow['total_wt']),
                'is_gift' => isset($productRow['is_gift']) ? $productRow['is_gift'] : null,
                'categories' => array($productRow['category']),
                'url' => $link->getProductLink(
                    $product,
                    $productRow['link_rewrite'],
                    $productRow['category'],
                    null,
                    $cart->id_lang,
                    $cart->id_shop,
                    $productRow['id_product_attribute'],
                    false,
                    false,
                    true
                ),
                'picture_url' => $link->getImageLink($productRow['link_rewrite'], $productRow['id_image'], 'large_default'),
                'requires_shipping' => !!(isset($productRow['is_virtual']) ? $productRow['is_virtual'] : $productDetails['is_virtual']),
                'taxes_included' => true,
            );

            if (isset($productRow['id_product_attribute']) && (int)$productRow['id_product_attribute']) {
                $unique_id = "$pid-{$productRow['id_product_attribute']}";

                if ($combinationName = $combinationsNames[$unique_id]) {
                    $data['variant_title'] = $combinationName;
                }
            }

            $items[] = $data;
        }

        return array("items" => $items);
    }

    /**
     * @param array $products
     * @return array
     */
    private static function getProductsDetails($products) {
        $sql = new DbQuery();
        $sql->select('p.`id_product`, p.`is_virtual`, m.`name` as manufacturer_name');
        $sql->from('product', 'p');
        $sql->innerJoin('manufacturer', 'm', 'm.`id_manufacturer` = p.`id_manufacturer`');

        $in = array();
        foreach ($products as $idx => $productRow) {
            $in[] = $productRow['id_product'];
        }

        $in = implode(", ", $in);
        $sql->where("p.`id_product` IN ({$in})");

        $db = Db::getInstance();
        $productsDetails = array();

        try {
            $results = $db->query($sql);
        } catch (PrestaShopDatabaseException $e) {
            return $productsDetails;
        }

        while ($result = $db->nextRow($results)) {
            $productsDetails[(int)$result['id_product']] = $result;
        }

        return $productsDetails;
    }

    /**
     * @param Cart $cart
     * @param array $products
     * @return array
     */
    private static function getProductsCombinations($cart, $products) {
        $sql = new DbQuery();
        $sql->select('CONCAT(p.`id_product`, "-", pa.`id_product_attribute`) as `unique_id`');

        $combinationName = new DbQuery();
        $combinationName->select('GROUP_CONCAT(DISTINCT CONCAT(agl.`name`, " - ", al.`name`) SEPARATOR ", ")');
        $combinationName->from('product_attribute', 'pa2');
        $combinationName->innerJoin('product_attribute_combination', 'pac', 'pac.`id_product_attribute` = pa2.`id_product_attribute`');
        $combinationName->innerJoin('attribute', 'a', 'a.`id_attribute` = pac.`id_attribute`');
        $combinationName->innerJoin('attribute_lang', 'al', 'a.id_attribute = al.id_attribute AND al.`id_lang` = ' . $cart->id_lang);
        $combinationName->innerJoin('attribute_group', 'ag', 'ag.`id_attribute_group` = a.`id_attribute_group`');
        $combinationName->innerJoin('attribute_group_lang', 'agl', 'ag.`id_attribute_group` = agl.`id_attribute_group` AND agl.`id_lang` = ' . $cart->id_lang);
        $combinationName->where('pa2.`id_product` = p.`id_product` AND pa2.`id_product_attribute` = pa.`id_product_attribute`');

        /** @noinspection PhpUnhandledExceptionInspection */
        $sql->select("({$combinationName->build()}) as combination_name");

        $sql->from('product', 'p');
        $sql->innerJoin('product_attribute', 'pa', 'pa.`id_product` = p.`id_product`');

        // DbQuery::where joins all where clauses with `) AND (` so for ORs we need a fully built where condition
        $where = '';
        $op = '';
        foreach ($products as $idx => $productRow) {
            if (!isset($productRow['id_product_attribute']) || !(int)$productRow['id_product_attribute']) {
                continue;
            }

            $where .= "{$op}(p.`id_product` = {$productRow['id_product']} AND pa.`id_product_attribute` = {$productRow['id_product_attribute']})";
            $op = ' OR ';
        }
        $sql->where($where);

        $db = Db::getInstance();
        $combinationsNames = array();

        try {
            $results = $db->query($sql);
        } catch (PrestaShopDatabaseException $e) {
            return $combinationsNames;
        }

        while ($result = $db->nextRow($results)) {
            $combinationsNames[$result['unique_id']] = $result['combination_name'];
        }

        return $combinationsNames;
    }
}
