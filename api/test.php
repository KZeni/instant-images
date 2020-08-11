<?php

/*
*  rest_api_init
*  Custom /resize route
*
*  @since 3.0
*/

add_action( 'rest_api_init', function () {
   $my_namespace = 'instant-images';
   $my_endpoint = '/test';
   register_rest_route( $my_namespace, $my_endpoint,
      array(
         'methods' => 'POST',
         'callback' => 'instant_images_test',
      )
   );
});



/*
*  test
*  Test REST API access
*
*  @param $request      $_PUT
*  @return $response    json
*  @since 3.2

*/

function instant_images_test( WP_REST_Request $request ) {

   if ( InstantImages::instant_img_has_access() ){

      error_reporting(E_ALL|E_STRICT);

      // Access is enable, send the response
      $response = array(
         'success' => true
      );

      // Send response as JSON
      wp_send_json($response);

   }
}
