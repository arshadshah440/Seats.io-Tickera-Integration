jQuery(document).ready(function ($) {
  $(".seatsio-ticket-select").on("change", function () {

    
    var selectedOption = $(this).find(":selected");
    var price = selectedOption.data("price") || "N/A";
    var category = $(this).data("category");

    $("#price-" + category).text(price);
  });




  $("#maping_form_am").on("submit", function (e) {
    e.preventDefault(); // Prevent page refresh

    var formData = $(this).serialize(); // Get form data

    $.ajax({
      type: "POST",
      url: "/wp-admin/admin-ajax.php", // WordPress AJAX URL
      data: formData + "&action=seatsio_save_mappings_ajax", // Include AJAX action
      success: function (response) {
        if (response.success) {
          // Update prices dynamically
          $(".seatsio-ticket-select").each(function () {
            var categoryKey = $(this).data("category");
            var selectedTicket = $(this).find("option:selected");
            var selectedPrice = selectedTicket.data("price");

            $("#price-" + categoryKey).text(
              selectedPrice ? selectedPrice : "N/A"
            );
          });
          alert("Mappings saved successfully!");
        } else {
          alert("Failed to save mappings.");
        }
      },
    });
  });



});
