(function (blocks, blockEditor, components, element, i18n, serverSideRender, data) {
  const { registerBlockType } = blocks;
  const { InspectorControls, useBlockProps } = blockEditor;
  const { PanelBody, TextControl, SelectControl, RangeControl, Notice } = components;
  const { createElement: el } = element;
  const { __ } = i18n;
  const ServerSideRender = serverSideRender;

  registerBlockType('mxp-local-search/related-posts', {
    title: __('MXP Related Articles', 'mxp-local-search'),
    description: __('Show semantically related articles from the MXP Local Search index.', 'mxp-local-search'),
    category: 'widgets',
    icon: 'networking',
    attributes: {
      limit: { type: 'number', default: 5 },
      mode: { type: 'string', default: '' },
      postId: { type: 'number', default: 0 },
      title: { type: 'string', default: __('Related articles', 'mxp-local-search') },
    },
    edit: function (props) {
      const attributes = props.attributes;
      const setAttributes = props.setAttributes;
      const currentPostId = data && data.select('core/editor') ? data.select('core/editor').getCurrentPostId() : 0;
      const previewAttributes = Object.assign({}, attributes, {
        postId: attributes.postId || currentPostId || 0,
      });

      return el(
        'div',
        useBlockProps(),
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Related articles settings', 'mxp-local-search') },
            el(TextControl, {
              label: __('Heading', 'mxp-local-search'),
              value: attributes.title || '',
              onChange: function (value) {
                setAttributes({ title: value });
              },
              help: __('Leave empty to hide the heading.', 'mxp-local-search'),
            }),
            el(RangeControl, {
              label: __('Number of articles', 'mxp-local-search'),
              value: attributes.limit || 5,
              min: 1,
              max: 20,
              onChange: function (value) {
                setAttributes({ limit: value || 5 });
              },
            }),
            el(SelectControl, {
              label: __('Search mode', 'mxp-local-search'),
              value: attributes.mode || '',
              options: [
                { label: __('Site default', 'mxp-local-search'), value: '' },
                { label: 'fast', value: 'fast' },
                { label: 'semantic', value: 'semantic' },
                { label: 'hybrid', value: 'hybrid' },
                { label: 'deep', value: 'deep' },
              ],
              onChange: function (value) {
                setAttributes({ mode: value });
              },
            }),
            el(TextControl, {
              label: __('Source post ID', 'mxp-local-search'),
              type: 'number',
              value: attributes.postId || '',
              onChange: function (value) {
                setAttributes({ postId: parseInt(value, 10) || 0 });
              },
              help: __('Leave blank to use the current post.', 'mxp-local-search'),
            })
          )
        ),
        el(Notice, { status: 'info', isDismissible: false }, __('This dynamic block renders related articles on the front end using the MXP Local Search index.', 'mxp-local-search')),
        el(ServerSideRender, {
          block: 'mxp-local-search/related-posts',
          attributes: previewAttributes,
          EmptyResponsePlaceholder: function () {
            return el('p', null, __('No related articles found yet. Index related content first, then refresh the preview.', 'mxp-local-search'));
          },
        })
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n, window.wp.serverSideRender, window.wp.data);
