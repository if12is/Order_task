<?php

namespace App\Http\Controllers;

use App\Mail\IngredientAlert;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        // Validate the request payload
        $validator = Validator::make($request->all(), [
            'products' => 'required|array',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'customer_name' => 'required|string',
            'customer_email' => 'required|email|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 400);
        }

        // Check if any of the ingredients have a stock level below the threshold
        $lowStockIngredients = Ingredient::where('stock', '<=', DB::raw('threshold * 0.5'))->get();

        if ($lowStockIngredients->isNotEmpty()) {
            // Return a message indicating that the shop is closed due to low stock
            return response()->json(['success' => false, 'message' => 'The shop is currently closed due to low stock.'], 403);
        }

        // Create a new order
        $order = Order::create([
            'customer_name' => $request->input('customer_name'),
            'customer_email' => $request->input('customer_email'),
        ]);

        $productDetails = [];
        // Initialize the total price to 0
        $totalPrice = 0;

        // Loop through the products in the request
        foreach ($request->products as $product) {
            // Check that the product has a valid quantity value
            if (!isset($product['quantity']) || $product['quantity'] < 1) {
                continue; // Skip this product if it doesn't have a valid quantity
            }

            // Find the product by id
            $productData = Product::find($product['product_id']);

            if (!$productData) {
                return response()->json(['success' => false, 'errors' => ['product_id' => ['Product not found']]], 404);
            }
            // Calculate the price of the product
            $price = $productData->price * $product['quantity'];
            // Add the price of the product to the total price
            $totalPrice += $price;

            $productDetails[] = [
                'product_id' => $productData->id,
                'price' => $productData->price,
                'quantity' => $product['quantity'],
                'total_price' => $totalPrice,
            ];

            // Attach the product to the order with the quantity
            $order->products()->attach($productData->id, ['quantity' => $product['quantity']]);

            // Loop through the ingredients of the product
            foreach ($productData->ingredients as $ingredient) {
                // Calculate the amount of ingredient consumed by the product quantity
                $amount = $ingredient->pivot->amount * $product['quantity'];

                // Update the stock of the ingredient by subtracting the amount
                $ingredient->update(['stock' => $ingredient->stock - $amount]);

                // Check if the stock of the ingredient is below 50% and an alert hasn't been sent already
                if ($ingredient->stock <= $ingredient->threshold) {
                    // Check if the alert has not been sent for this ingredient
                    if (!$ingredient->alert_sent) {
                        // Send an email to alert the merchant
                        Mail::to('aa1980461@gmail.com')->send(new IngredientAlert($ingredient));

                        // Mark the alert as sent for this ingredient
                        $ingredient->alert_sent = true;
                        $ingredient->save();
                    }
                }
            }
        }

        // Return a success response with the order and product details
        return response()->json(['success' => true, 'order' => $order, 'product' => $productDetails]);
    }
}
