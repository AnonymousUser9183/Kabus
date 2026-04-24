@extends('layouts.app')

@section('content')

@if($popup)
<input type="checkbox" id="pop-up-toggle" checked>
<div class="pop-up-container">
    <div class="pop-up-card">
        <h2 class="pop-up-title">{{ $popup->title }}</h2>
        <div class="pop-up-content">{{ $popup->message }}</div>
        <div class="pop-up-button-container">
            <label for="pop-up-toggle" class="pop-up-close-btn">
                Acknowledge & Continue
            </label>
        </div>
    </div>
</div>
@endif

<div class="home-container">
    @if(count($adSlots) > 0)
        <div class="home-highlight-title-wrapper">
            <h2 class="home-highlight-heading">Advertised Products</h2>
        </div>
        <div class="home-highlight-container">
            @for($i = 1; $i <= 8; $i++)
                @if(isset($adSlots[$i]))
                    <div class="home-highlight-card">
                        {{-- Product Image --}}
                        <div class="home-highlight-image">
                            <img src="{{ $adSlots[$i]['product']->product_picture_url }}" 
                                 alt="{{ $adSlots[$i]['product']->name }}">
                        </div>

                        {{-- Product Details --}}
                        <div class="home-highlight-content">
                            <div class="home-highlight-header">
                                <div class="home-highlight-title-section">
                                    <h3 class="home-highlight-title">{{ $adSlots[$i]['product']->name }}</h3>
                                    <div class="home-highlight-badges">
                                    <span class="home-highlight-type home-highlight-type-{{ $adSlots[$i]['product']->type }}">
                                        {{ ucfirst($adSlots[$i]['product']->type) }}
                                    </span>
                                        <span class="home-highlight-vendor">
                                            <a href="{{ route('vendors.show', ['username' => $adSlots[$i]['vendor']->username]) }}" class="home-highlight-vendor-link">
                                                {{ $adSlots[$i]['vendor']->username }}
                                            </a>
                                        </span>
                                        <span class="home-highlight-badge home-highlight-category">
                                            {{ $adSlots[$i]['product']->category->name }}
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="home-highlight-price-section">
                                    <div class="home-highlight-price">
                                        ${{ number_format($adSlots[$i]['product']->price, 2) }}
                                    </div>
                                    @if($adSlots[$i]['xmr_price'] !== null)
                                        <div class="home-highlight-xmr">
                                            ≈ ɱ{{ number_format($adSlots[$i]['xmr_price'], 4) }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="home-highlight-details">
                                <div class="home-highlight-info">
                                    <div class="home-highlight-stock">
                                        {{ number_format($adSlots[$i]['product']->stock_amount) }} 
                                        {{ $adSlots[$i]['measurement_unit'] }}
                                    </div>

                                    <div class="home-highlight-shipping">
                                        {{ $adSlots[$i]['product']->ships_from }} ➜ {{ $adSlots[$i]['product']->ships_to }}
                                    </div>

                                    @if(!empty($adSlots[$i]['bulk_options']))
                                        <div class="home-highlight-bulk-preview">
                                            {{ count($adSlots[$i]['bulk_options']) }} Bulk Offers
                                        </div>
                                    @endif

                                        <div class="home-highlight-delivery-preview">
                                            {{ count($adSlots[$i]['delivery_options']) }} Delivery Options
                                        </div>
                                </div>

                                <div class="home-highlight-action">
                                    <a href="{{ route('products.show', $adSlots[$i]['product']) }}" 
                                       class="home-highlight-button">
                                        View Product
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endfor
        </div>
    @endif

    @if(count($featuredProducts) > 0)
        <div class="home-highlight-title-wrapper">
            <h2 class="home-highlight-heading">Featured Products</h2>
        </div>
        <div class="home-highlight-container">
            @foreach($featuredProducts as $featured)
                <div class="home-highlight-card">
                    {{-- Product Image --}}
                    <div class="home-highlight-image">
                        <img src="{{ $featured['product']->product_picture_url }}" 
                             alt="{{ $featured['product']->name }}">
                    </div>

                    {{-- Product Details --}}
                    <div class="home-highlight-content">
                        <div class="home-highlight-header">
                            <div class="home-highlight-title-section">
                                <h3 class="home-highlight-title">{{ $featured['product']->name }}</h3>
                                <div class="home-highlight-badges">
                                <span class="home-highlight-type home-highlight-type-{{ $featured['product']->type }}">
                                    {{ ucfirst($featured['product']->type) }}
                                </span>
                                    <span class="home-highlight-vendor">
                                        <a href="{{ route('vendors.show', ['username' => $featured['vendor']->username]) }}" class="home-highlight-vendor-link">
                                            {{ $featured['vendor']->username }}
                                        </a>
                                    </span>
                                    <span class="home-highlight-badge home-highlight-category">
                                        {{ $featured['product']->category->name }}
                                    </span>
                                </div>
                            </div>
                            
                            <div class="home-highlight-price-section">
                                <div class="home-highlight-price">
                                    ${{ number_format($featured['product']->price, 2) }}
                                </div>
                                @if($featured['xmr_price'] !== null)
                                    <div class="home-highlight-xmr">
                                        ≈ ɱ{{ number_format($featured['xmr_price'], 4) }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="home-highlight-details">
                            <div class="home-highlight-info">
                                <div class="home-highlight-stock">
                                    {{ number_format($featured['product']->stock_amount) }} 
                                    {{ $featured['measurement_unit'] }}
                                </div>

                                <div class="home-highlight-shipping">
                                    {{ $featured['product']->ships_from }} ➜ {{ $featured['product']->ships_to }}
                                </div>

                                @if(!empty($featured['bulk_options']))
                                    <div class="home-highlight-bulk-preview">
                                        {{ count($featured['bulk_options']) }} Bulk Offers
                                    </div>
                                @endif

                                    <div class="home-highlight-delivery-preview">
                                        {{ count($featured['delivery_options']) }} Delivery Options
                                    </div>
                            </div>

                            <div class="home-highlight-action">
                                <a href="{{ route('products.show', $featured['product']) }}" 
                                   class="home-highlight-button">
                                    View Product
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if(count($adSlots) === 0 && count($featuredProducts) === 0)
    <div class="home-welcome-message">
        <h1 class="home-title">Kabus Marketplace Script 2.0</h1>
    
        <p class="home-text">Hello Everyone,</p>
    
        <p class="home-text">
            I am proud to announce the re-release of the Kabus Marketplace Script. Originally created by the developer Sukunetsiz who has recently vanished from the internet. This reboot of Kabus Marketplace Script is developed by AnonymousUser9183 the lead developer of The Erebus Project.
        </p>
    
        <p class="home-text">
            This upgraded version of Kabus Marketplace Script runs on the latest variant of Laravel 13 upgraded from Laravel 11.9. The Erebus Development Team will now be the primary maintainer of the Kabus Marketplace Script. We will release consistent security updates, and new feature integrations on a regular basis.
        </p>
    
        <p class="home-text">Support Development of Erebus & Kabus</p>
    
        <ul class="home-list">
            <li>Continuous Upgrades & New Features</li>
            <li>I will be constantly developing, upgrading, adding new features and repairing bugs in this script. Please consider donating to support AnonymousUser9183 and The Erebus Project. To donate please send a CashApp payment to $TheErebusProject or $AnonymousUser9183 or you may send Monero to this address: 45umQEDfN52gzHMpUxkK8TUAeZjFgzb2VDmZArgx4iTHeGY4gb2KrtqZC691Ff9pHaJeUFF1oBZAGQHTHzps7icg5cdptMG</li>
        </ul>
    
        <div class="home-important">
            <strong>Security Reminder</strong>
            <p class="home-text">
            Please use this program with caution. Using this program to host an illegal darknet marketplace is forbidden. By using this marketplace script you agree that you are using this script to create a legal marketplace, selling legally aquired products such as electronics, in a style similar to eBay or Amazon, using this program on the Tor network or I2p is forbidden.
            </p>
        </div>
    
        <p class="home-text">
            I look forward to developing Erebus & Kabus into an amazing ecosystem that contributes greatly to the community.
        </p>
    
        <div class="home-signature">
            <p>Your's Truly,<br>AnonymousUser9183 - Leader of The Erebus Project</p>
        </div>
    </div>
    @endif
</div>
@endsection
