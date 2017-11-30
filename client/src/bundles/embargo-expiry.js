/* eslint-env browser */

window.jQuery.entwine('ss', ($) => {
  const showHidePublishButton = (hasEmbargo) => {
    const saveButton = $('button[name="action_save"]');
    const publishButton = $('button[name="action_publish"]');

    if (hasEmbargo) {
      publishButton.detach();
    } else {
      publishButton.insertAfter(saveButton);
    }
  };

  $('input[name="PublishOnDate"]').entwine({
    onmatch() {
      showHidePublishButton($(this).val().length > 0);
    },
    onchange() {
      showHidePublishButton($(this).val().length > 0);
    },
  });
});
