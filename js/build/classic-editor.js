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

// js/src/classic-editor.js
jQuery(
  function($) {
    $.ajaxPrefilter(
      function(options, originalOptions, jqXHR) {
        var lang = $(".post_lang_choice").val();
        if ("string" === typeof options.data && -1 !== options.url.indexOf("action=ajax-tag-search") && lang) {
          options.data = "lang=" + lang + "&" + options.data;
        }
      }
    );
  }
);
jQuery(
  function($) {
    tagBox.get = function(id) {
      var tax = id.substr(id.indexOf("-") + 1);
      var data = {
        action: "get-tagcloud",
        lang: $(".post_lang_choice").val(),
        tax
      };
      $.post(
        ajaxurl,
        data,
        function(r, stat) {
          if (0 == r || "success" != stat) {
            r = wpAjax.broken;
          }
          r = $("<div />").addClass("the-tagcloud").attr("id", "tagcloud-" + tax).html(r);
          $("a", r).on(
            "click",
            function() {
              tagBox.flushTags($(this).closest(".inside").children(".tagsdiv"), this);
              return false;
            }
          );
          var tagCloud = $("#tagcloud-" + tax);
          var v = tagCloud.css("display");
          if (v) {
            $("#tagcloud-" + tax).replaceWith(r);
            $("#tagcloud-" + tax).css("display", v);
          } else {
            $("#" + id).after(r);
          }
        }
      );
    };
  }
);
jQuery(
  function($) {
    var taxonomies = new Array();
    $(".categorydiv").each(
      function() {
        var this_id = $(this).attr("id"), taxonomyParts, taxonomy;
        taxonomyParts = this_id.split("-");
        taxonomyParts.shift();
        taxonomy = taxonomyParts.join("-");
        taxonomies.push(taxonomy);
        $("#" + taxonomy + "-add-submit").before(
          // phpcs:ignore WordPressVIPMinimum.JS.HTMLExecutingFunctions.before
          $("<input />").attr("type", "hidden").attr("id", taxonomy + "-lang").attr("name", "term_lang_choice").attr("value", $(".post_lang_choice").val())
        );
      }
    );
    initializeLanguageOldValue();
    $(".post_lang_choice").on(
      "change",
      function(event) {
        const confirmationModal = initializeConfirmationModal();
        const { dialogContainer: dialog } = confirmationModal;
        let { dialogResult } = confirmationModal;
        const selectedOption = event.target;
        if ($(this).data("old-value") !== selectedOption.value && !isEmptyPost()) {
          dialog.dialog("open");
        } else {
          dialogResult = Promise.resolve();
        }
        dialogResult.then(
          () => {
            var lang = selectedOption.options[selectedOption.options.selectedIndex].lang;
            var dir = $('.strope-translation-column > span[lang="' + lang + '"]').attr("dir");
            var data = {
              // phpcs:ignore PEAR.Functions.FunctionCallSignature.Indent
              action: "post_lang_choice",
              lang: selectedOption.value,
              post_type: $("#post_type").val(),
              taxonomies,
              post_id: $("#post_ID").val(),
              _strope_nonce: $("#_strope_nonce").val()
            };
            $.post(
              ajaxurl,
              data,
              function(response) {
                var res = wpAjax.parseAjaxResponse(response, "strope-ajax-response");
                $.each(
                  res.responses,
                  function() {
                    switch (this.what) {
                      case "translations":
                        $(".translations").html(this.data);
                        initMetaboxAutoComplete();
                        break;
                      case "taxonomy":
                        var tax = this.data;
                        $("#" + tax + "checklist").html(this.supplemental.all);
                        $("#" + tax + "checklist-pop").html(this.supplemental.populars);
                        $("#new" + tax + "_parent").replaceWith(this.supplemental.dropdown);
                        $("#" + tax + "-lang").val($(".post_lang_choice").val());
                        break;
                      case "pages":
                        $("#parent_id").html(this.data);
                        break;
                      case "flag":
                        $(".strope-select-flag").html(this.data);
                        break;
                      case "permalink":
                        var div = $("#edit-slug-box");
                        if ("-1" != this.data && div.children().length) {
                          div.html(this.data);
                        }
                        break;
                    }
                  }
                );
                initializeLanguageOldValue();
                $(".tagcloud-link").each(
                  function() {
                    var id = $(this).attr("id");
                    tagBox.get(id);
                  }
                );
                $("body").removeClass("strope-dir-rtl").removeClass("strope-dir-ltr").addClass("strope-dir-" + dir);
                $("#content_ifr").contents().find("html").attr("lang", lang).attr("dir", dir);
                $("#content_ifr").contents().find("body").attr("dir", dir);
                strope.media.resetAllAttachmentsCollections();
              }
            );
          },
          () => {
          }
          // Do nothing when promise is rejected by clicking the Cancel dialog button.
        );
        function isEmptyPost() {
          const title = $("input#title").val();
          const content = $("textarea#content").val();
          const excerpt = $("textarea#excerpt").val();
          return !title && !content && !excerpt;
        }
      }
    );
    initMetaboxAutoComplete();
  }
);
var strope = window.strope || {};
_.extend(strope, { media: {} });
var media = _.extend(
  strope.media,
  /** @lends strope.media.prototype */
  {
    /**
     * TODO: Find a way to delete references to Attachments collections that are not used anywhere else.
     *
     * @type {wp.media.model.Attachments}
     */
    attachmentsCollections: [],
    /**
     * Imitates { @see wp.media.query } but log all Attachments collections created.
     *
     * @param {Object} [props]
     * @return {wp.media.model.Attachments}
     */
    query: function(props) {
      var attachments = strope.media.query.delegate(props);
      strope.media.attachmentsCollections.push(attachments);
      return attachments;
    },
    resetAllAttachmentsCollections: function() {
      this.attachmentsCollections.forEach(
        function(attachmentsCollection) {
          attachmentsCollection.reset();
          if (attachmentsCollection.mirroring) {
            attachmentsCollection.mirroring._hasMore = true;
            attachmentsCollection.mirroring.reset();
          }
        }
      );
    }
  }
);
if ("undefined" !== typeof wp && "undefined" !== typeof wp.media) {
  media.query = _.extend(
    media.query,
    /** @lends strope.media.query prototype */
    {
      /**
       * @type Function References WordPress { @see wp.media.query } constructor
       */
      delegate: wp.media.query
    }
  );
  wp.media.query = media.query;
}
