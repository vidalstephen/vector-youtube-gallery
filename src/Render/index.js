/**
 * Editor script for the vectoryt/gallery Gutenberg block — Phase 10.4.
 *
 * Hard constraints:
 *   - **No YouTube API calls.** The block-editor reads only local data:
 *     the WP REST namespace `/vyg/v1/feeds`. Block render itself is
 *     server-rendered by `render.php` via the shared Renderer.
 *   - **Server-side render is authoritative.** The client only adjusts
 *     attributes and shows a preview placeholder. The rendered markup
 *     flows through `render_block_vectoryt_gallery()` server-side.
 *   - **No API tokens / secrets in client state.** Picker fetches only
 *     saved feeds (a list operator-authored via the Feed Builder) and
 *     presets (Phase 9.6 — public CSS bundles).
 */
( function ( wp, settings ) {
    "use strict";
    if ( ! wp || ! wp.blockEditor || ! wp.element ) {
        return;
    }
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
    var InspectorControls = wp.blockEditor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var RangeControl = wp.components.RangeControl;
    var TextControl = wp.components.TextControl;
    var ToggleControl = wp.components.ToggleControl;
    var Placeholder = wp.components.Placeholder;
    var Spinner = wp.components.Spinner;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var apiFetch = wp.apiFetch;

    /**
     * Hook: load the available feeds from /vyg/v1/feeds for the picker.
     * Returns { options: [{value,label}], loading, error }.
     */
    function useFeedOptions() {
        var state = useState( { loading: true, options: [], error: null } );
        var data = state[0];
        var setData = state[1];
        useEffect( function () {
            var cancelled = false;
            apiFetch( { path: "/vyg/v1/feeds?per_page=200" } )
                .then( function ( rows ) {
                    if ( cancelled ) {
                        return;
                    }
                    var opts = [
                        { value: "", label: __( "— Pick a saved feed —", "vector-youtube-gallery" ) }
                    ];
                    if ( Array.isArray( rows ) ) {
                        for ( var i = 0; i < rows.length; i++ ) {
                            var f = rows[i] || {};
                            opts.push( {
                                value: String( f.feed_uuid || "" ),
                                label: String( f.name || f.feed_uuid || "(unnamed)" )
                            } );
                        }
                    }
                    setData( { loading: false, options: opts, error: null } );
                } )
                .catch( function ( err ) {
                    if ( cancelled ) {
                        return;
                    }
                    setData( {
                        loading: false,
                        options: [ { value: "", label: __( "— Pick a saved feed —", "vector-youtube-gallery" ) } ],
                        error: err && err.message ? err.message : "feed-list-failed"
                    } );
                } );
            return function () { cancelled = true; };
        }, [] );
        return data;
    }

    var registerBlockType = wp.blocks.registerBlockType;
    registerBlockType( settings.name || "vectoryt/gallery", {
        edit: function ( props ) {
            var attrs = props.attributes;
            var setAttrs = props.setAttributes;

            var feedPicker = useFeedOptions();

            var feedOptions = feedPicker.loading
                ? [ { value: "", label: __( "Loading…", "vector-youtube-gallery" ) } ]
                : feedPicker.options;

            var layoutOptions = ( settings.layouts || [ "grid", "list", "featured", "shorts", "live", "masonry", "carousel", "hero" ] ).map( function ( slug ) {
                return { value: slug, label: slug.charAt(0).toUpperCase() + slug.slice(1) };
            } );

            var presetOptions = [ "default", "minimal", "cinema", "pastel", "developer" ].map( function ( slug ) {
                return { value: slug, label: slug.charAt(0).toUpperCase() + slug.slice(1) };
            } );

            var blockProps = useBlockProps ? useBlockProps( { className: "vyg-block-editor" } ) : {};

            var inspector = el( InspectorControls, null,
                el( PanelBody, { title: __( "Source", "vector-youtube-gallery" ), initialOpen: true },
                    el( SelectControl, {
                        label: __( "Saved feed (preferred)", "vector-youtube-gallery" ),
                        value: attrs.feed_uuid || "",
                        options: feedOptions,
                        onChange: function ( v ) { setAttrs( { feed_uuid: v } ); },
                        help: feedPicker.error
                            ? __( "Couldn't load feeds — check permissions or use the Source UUID fallback below.", "vector-youtube-gallery" )
                            : null
                    } ),
                    el( TextControl, {
                        label: __( "Source UUID (legacy)", "vector-youtube-gallery" ),
                        value: attrs.source_uuid || "",
                        onChange: function ( v ) { setAttrs( { source_uuid: v } ); },
                        placeholder: "(optional)",
                        help: __( "Used when no saved feed is selected.", "vector-youtube-gallery" )
                    } )
                ),
                el( PanelBody, { title: __( "Layout", "vector-youtube-gallery" ), initialOpen: true },
                    el( SelectControl, {
                        label: __( "Layout", "vector-youtube-gallery" ),
                        value: attrs.layout || "grid",
                        options: layoutOptions,
                        onChange: function ( v ) { setAttrs( { layout: v } ); }
                    } ),
                    el( RangeControl, {
                        label: __( "Columns", "vector-youtube-gallery" ),
                        value: Number( attrs.columns || 3 ),
                        min: 1, max: 6,
                        onChange: function ( v ) { setAttrs( { columns: v } ); }
                    } ),
                    el( RangeControl, {
                        label: __( "Items per page", "vector-youtube-gallery" ),
                        value: Number( attrs.per_page || 12 ),
                        min: 1, max: 200,
                        onChange: function ( v ) { setAttrs( { per_page: v } ); }
                    } )
                ),
                el( PanelBody, { title: __( "Filter & sort", "vector-youtube-gallery" ), initialOpen: false },
                    el( TextControl, {
                        label: __( "Content type filter", "vector-youtube-gallery" ),
                        value: attrs.content_type || "",
                        onChange: function ( v ) { setAttrs( { content_type: v } ); },
                        placeholder: "e.g. short_confirmed,live_active"
                    } ),
                    el( SelectControl, {
                        label: __( "Order by", "vector-youtube-gallery" ),
                        value: attrs.orderby || "published_at",
                        options: [
                            { value: "published_at",      label: __( "Published date", "vector-youtube-gallery" ) },
                            { value: "title",             label: __( "Title", "vector-youtube-gallery" ) },
                            { value: "view_count",        label: __( "View count", "vector-youtube-gallery" ) },
                            { value: "last_refreshed_at", label: __( "Last refreshed", "vector-youtube-gallery" ) }
                        ],
                        onChange: function ( v ) { setAttrs( { orderby: v } ); }
                    } ),
                    el( SelectControl, {
                        label: __( "Order", "vector-youtube-gallery" ),
                        value: attrs.order || "DESC",
                        options: [
                            { value: "DESC", label: __( "Descending", "vector-youtube-gallery" ) },
                            { value: "ASC",  label: __( "Ascending", "vector-youtube-gallery" ) }
                        ],
                        onChange: function ( v ) { setAttrs( { order: v } ); }
                    } ),
                    el( SelectControl, {
                        label: __( "Pagination", "vector-youtube-gallery" ),
                        value: attrs.pagination || "none",
                        options: [
                            { value: "none",      label: __( "None", "vector-youtube-gallery" ) },
                            { value: "load_more", label: __( "Load more (JS button)", "vector-youtube-gallery" ) }
                        ],
                        onChange: function ( v ) { setAttrs( { pagination: v } ); }
                    } )
                ),
                el( PanelBody, { title: __( "Style & SEO", "vector-youtube-gallery" ), initialOpen: false },
                    el( SelectControl, {
                        label: __( "Style preset", "vector-youtube-gallery" ),
                        value: attrs.preset || "default",
                        options: presetOptions,
                        onChange: function ( v ) { setAttrs( { preset: v } ); }
                    } ),
                    el( ToggleControl, {
                        label: __( "Emit Schema.org JSON-LD", "vector-youtube-gallery" ),
                        checked: !! attrs.schema_enabled,
                        onChange: function ( v ) { setAttrs( { schema_enabled: !! v } ); }
                    } )
                )
            );

            var sourceOK = !!( attrs.feed_uuid || attrs.source_uuid );
            var preview  = sourceOK
                ? el( Fragment, null,
                      el( "img", { src: "data:image/svg+xml;utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 80'/%3E", "aria-hidden": "true" } ),
                      el( "p", null, __( "Server-rendered preview — click 'Preview' to view the gallery.", "vector-youtube-gallery" ) )
                  )
                : el( Placeholder, {
                      icon: "video-alt3",
                      label: __( "YouTube Gallery", "vector-youtube-gallery" ),
                      instructions: __( "Pick a saved feed or paste a source UUID in the sidebar.", "vector-youtube-gallery" )
                  } );

            return el( Fragment, null,
                inspector,
                el( "div", blockProps, preview )
            );
        },

        /** Save: we don't save any markup; the server renders it. */
        save: function () { return null; }
    } );
} )( window.wp, window.VYG_BLOCK || {} );
