/**
 * Vector YouTube Gallery — minimal block editor script.
 *
 * Phase 4 ships a placeholder editor experience. Full InspectorControls
 * (source picker dropdown, layout select, etc.) is Phase 4.5 work.
 */
( function ( blocks, element, blockEditor ) {
    'use strict';
    var el = element.createElement;
    var __ = wp.i18n.__;
    var useBlockProps = blockEditor.useBlockProps;

    blocks.registerBlockType( 'vectoryt/gallery', {
        edit: function ( props ) {
            var attrs = props.attributes;
            var blockProps = useBlockProps( { className: 'vyg-block-editor' } );
            return el(
                'div',
                blockProps,
                el(
                    'p',
                    { style: { padding: '1em', border: '1px dashed #ccc', background: '#f9f9f9' } },
                    el( 'strong', null, __( 'Vector YouTube Gallery', 'vector-youtube-gallery' ) ),
                    el( 'br' ),
                    attrs.source_uuid
                        ? __( 'Source: ', 'vector-youtube-gallery' ) + attrs.source_uuid + ' · ' + attrs.layout
                        : __( 'Select a source in the block settings.', 'vector-youtube-gallery' )
                )
            );
        },
        save: function () {
            return null; // server-rendered
        },
    } );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor );