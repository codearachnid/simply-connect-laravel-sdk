{{-- Inlines the tiny window.SimplyConnect helper (once per page); checkout.js is loaded from Nuvei's CDN on first mount. --}}
@once('simply-connect-scripts')
<script{!! $attributes !!}>{!! file_get_contents(\SimplyConnect\Laravel\SimplyConnectLaravelServiceProvider::JS_PATH) !!}</script>
@endonce
