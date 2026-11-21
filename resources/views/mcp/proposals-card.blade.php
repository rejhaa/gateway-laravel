{{--
    The card claude.ai draws under list_proposals. ProposalsCard passes the
    gateway's URL and a new card token, and the script does the rest. The
    stylesheet and the script are inlined from their files, because a card is
    one self-contained HTML document.
--}}
<x-mcp::app :title="$title" class="gw">
    <x-slot:head>
        <style>
            {!! file_get_contents(resource_path('css/proposals.css')) !!}

            .gw-card {
                display: grid;
                gap: 16px;
                padding: 16px;
            }
        </style>
        <script type="application/json" id="card-config">@json($config)</script>
        <script type="module">
            {!! file_get_contents(resource_path('js/proposals-card.js')) !!}
        </script>
    </x-slot:head>

    <main id="card" class="gw-card">
        <p class="gw-state">Loading</p>
    </main>
</x-mcp::app>
