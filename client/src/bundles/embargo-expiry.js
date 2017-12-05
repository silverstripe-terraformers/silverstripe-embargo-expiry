/* eslint-env browser */

window.jQuery.entwine('ss', ($) => {
  let publishButton = null;
  let saveButton = null;

  const showHidePublishButton = (hasEmbargo) => {
    if (hasEmbargo) {
      publishButton.detach();
    } else {
      publishButton.insertAfter(saveButton);
    }
  };

  $('input[name="PublishOnDate"]').entwine({
    onmatch() {
      // Any time we match this field, make sure we have the latest instance of our buttons.
      publishButton = $('button[name="action_publish"]');
      saveButton = $('button[name="action_save"]');

      showHidePublishButton($(this).val().length > 0);
    },
    onchange() {
      showHidePublishButton($(this).val().length > 0);
    },
  });
});
