// js/src/lib/confirmation-modal.js
var languagesList = jQuery(".post_lang_choice");
var initializeConfirmationModal = () => {
  const { __ } = wp.i18n;
  const dialogContainer = jQuery(
    "<div/>",
    {
      id: "strope-dialog",
      style: "display:none;"
    }
  ).text(__("Are you sure you want to change the language of the current content?", "strope-translate"));
  languagesList.after(dialogContainer);
  const dialogResult = new Promise(
    (confirm, cancel) => {
      const confirmDialog = (what) => {
        switch (what) {
          // phpcs:ignore PEAR.Functions.FunctionCallSignature.Indent
          case "yes":
            languagesList.data("old-value", languagesList.children(":selected").first().val());
            confirm();
            break;
          case "no":
            languagesList.val(languagesList.data("old-value"));
            cancel("Cancel");
            break;
        }
        dialogContainer.dialog("close");
      };
      const dialogOptions = {
        autoOpen: false,
        modal: true,
        draggable: false,
        resizable: false,
        title: __("Change language", "strope-translate"),
        minWidth: 600,
        maxWidth: "100%",
        open: function(event, ui) {
          if (jQuery("body").hasClass("rtl")) {
            jQuery(this).parent().css(
              {
                right: jQuery(this).parent().css("left"),
                left: "auto"
              }
            );
          }
        },
        close: function(event, ui) {
          confirmDialog("no");
        },
        buttons: [
          {
            text: __("OK", "strope-translate"),
            click: function(event) {
              confirmDialog("yes");
            }
          },
          {
            text: __("Cancel", "strope-translate"),
            click: function(event) {
              confirmDialog("no");
            }
          }
        ]
      };
      if (jQuery.ui.version >= "1.12.0") {
        Object.assign(dialogOptions, { classes: { "ui-dialog": "strope-confirmation-modal" } });
      } else {
        Object.assign(dialogOptions, { dialogClass: "strope-confirmation-modal" });
      }
      dialogContainer.dialog(dialogOptions);
    }
  );
  return { dialogContainer, dialogResult };
};
var initializeLanguageOldValue = () => {
  languagesList.attr("data-old-value", languagesList.children(":selected").first().val());
};

// js/src/lib/metabox-autocomplete.js
function initMetaboxAutoComplete() {
  jQuery(".tr_lang").each(
    function() {
      var tr_lang = jQuery(this).attr("id").substring(8);
      var td = jQuery(this).parent().parent().siblings(".strope-edit-column");
      jQuery(this).autocomplete(
        {
          minLength: 0,
          source: ajaxurl + "?action=strope_posts_not_translated&post_language=" + jQuery(".post_lang_choice").val() + "&translation_language=" + tr_lang + "&post_type=" + jQuery("#post_type").val() + "&_strope_nonce=" + jQuery("#_strope_nonce").val(),
          select: function(event, ui) {
            jQuery("#htr_lang_" + tr_lang).val(ui.item.id);
            td.html(ui.item.link);
          }
        }
      );
      jQuery(this).on(
        "blur",
        function() {
          if (!jQuery(this).val()) {
            jQuery("#htr_lang_" + tr_lang).val(0);
            td.html(td.siblings(".hidden").children().clone());
          }
        }
      );
    }
  );
}

// js/src/lib/filter-path-middleware.js
var filterPathMiddleware = (options, filteredRoutes, filter) => {
  const cleanPath = options.path.split("?")[0].replace(/^\/+|\/+$/g, "");
  return Object.values(filteredRoutes).find((path) => cleanPath === path) ? filter(options) : options;
};
var filter_path_middleware_default = filterPathMiddleware;

// js/src/block-editor.js
wp.apiFetch.use(
  function(options, next) {
    if ("undefined" !== typeof options.url || "undefined" === typeof stropeFilteredRoutes) {
      return next(options);
    }
    return next(filter_path_middleware_default(options, stropeFilteredRoutes, addLanguageParameter));
  }
);
function getCurrentLanguage() {
  const lang = document.querySelector("[name=post_lang_choice]");
  if (null === lang) {
    return stropeDefaultLanguage;
  }
  return lang.value;
}
function addLanguageParameter(options) {
  if ("undefined" === typeof options.data || null === options.data) {
    options.path += (options.path.indexOf("?") >= 0 ? "&lang=" : "?lang=") + getCurrentLanguage();
  } else {
    options.data.lang = getCurrentLanguage();
  }
  return options;
}
jQuery(
  function($) {
    initializeLanguageOldValue();
    $(".post_lang_choice").on(
      "change",
      function(event) {
        const { select, dispatch, subscribe } = wp.data;
        const emptyPost = isEmptyPost();
        const { addQueryArgs } = wp.url;
        const confirmationModal = initializeConfirmationModal();
        const { dialogContainer: dialog } = confirmationModal;
        let { dialogResult } = confirmationModal;
        const selectedOption = event.target;
        if (location.pathname.match(/post-new.php/gi) && emptyPost) {
          reloadPageForEmptyPost(selectedOption.value);
        }
        if ($(this).data("old-value") !== selectedOption.value && !emptyPost) {
          dialog.dialog("open");
        } else {
          initializeLanguageOldValue();
          dialogResult = Promise.resolve();
        }
        dialogResult.then(
          () => {
            let data = {
              // phpcs:ignore PEAR.Functions.FunctionCallSignature.Indent
              action: "post_lang_choice",
              lang: selectedOption.value,
              post_type: $("#post_type").val(),
              post_id: $("#post_ID").val(),
              _strope_nonce: $("#_strope_nonce").val()
            };
            $.post(
              ajaxurl,
              data,
              function() {
                blockEditorSavePostAndReloadPage();
              }
            );
          },
          () => {
          }
          // Do nothing when promise is rejected by clicking the Cancel dialog button.
        );
        function isEmptyPost() {
          const editor = select("core/editor");
          return !editor.getEditedPostAttribute("title")?.trim() && !editor.getEditedPostContent() && !editor.getEditedPostAttribute("excerpt")?.trim();
        }
        function reloadPageForEmptyPost(lang) {
          if (-1 != location.search.indexOf("new_lang")) {
            window.location.search = window.location.search.replace(/(?:new_lang=[^&]*)(&)?(.*)/, "new_lang=" + lang + "$1$2");
          } else {
            window.location.search = window.location.search + (-1 != window.location.search.indexOf("?") ? "&" : "?") + "new_lang=" + lang;
          }
        }
        ;
        function blockEditorSavePostAndReloadPage() {
          let unsubscribe = null;
          const previousPost = select("core/editor").getCurrentPost();
          const savePostIsDone = new Promise(
            function(resolve, reject) {
              unsubscribe = subscribe(
                function() {
                  const post = select("core/editor").getCurrentPost();
                  const { id, status, type } = post;
                  const error = select("core").getLastEntitySaveError(
                    "postType",
                    type,
                    id
                  );
                  if (error) {
                    reject();
                  }
                  if (previousPost.modified !== post.modified) {
                    if (location.pathname.match(/post-new.php/gi) && status !== "auto-draft" && id) {
                      window.history.replaceState(
                        { id },
                        "Post " + id,
                        addQueryArgs("post.php", { post: id, action: "edit" })
                      );
                    }
                    resolve();
                  }
                }
              );
            }
          );
          dispatch("core/editor").savePost();
          savePostIsDone.then(
            function() {
              window.location.reload();
            },
            function() {
              unsubscribe();
            }
          ).catch(
            function() {
              unsubscribe();
            }
          );
        }
        ;
      }
    );
    initMetaboxAutoComplete();
  }
);
