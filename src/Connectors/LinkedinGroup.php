<?php

namespace Borfast\Socializr\Connectors;

use Borfast\Socializr\Exceptions\LinkedinPostingException;
use Exception;
use GuzzleHttp\Client as Guzzle;

use Borfast\Socializr\Post;
use Borfast\Socializr\Profile;
use Borfast\Socializr\Response;

class LinkedinGroup extends AbstractConnector
{
    public static $provider = 'linkedin';

    public function post(Post $post)
    {
        $group_id = $post->options['group_id'];
        $token = $this->service->getStorage()->retrieveAccessToken('Linkedin')->getAccessToken();
        $path = '/groups/'.$group_id.'/posts?format=json&oauth2_access_token='.$token;
        $params = array(
            'title' => $post->title,
            'summary' => '',
            'content' => [
                'title' => $post->title . ' @',
                'submitted-url' => $post->url,
                'submitted-image-url' => $post->image_url,
                'description' => $post->body,
            ],
        );
        $params = json_encode($params);



        // Linkedin API requires the Content-Type header set to application/json
        $url = 'https://api.linkedin.com/v1'.$path;
        $options = [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $params
        ];
        $client = new Guzzle();
        $result = $client->post($url, $options);

        if ($result->getStatusCode() > 300) {
            $msg = "Error posting to Linkedin group. Error code from Linkedin: %s. Error message from Linkedin: %s";
            $msg = sprintf($msg, $result->status_code, json_decode($result->body)->message);
            throw new LinkedinPostingException($msg, $result->status_code);
        }

        $response = new Response;
        $response->setRawResponse($result); // This is already JSON.
        $response->setProvider(static::$provider);
        //$response->setPostId($result->getHeader('x-li-uuid'));

        // As amazing as it may sound, there's a three year old bug that LinkedIn
        // knows of but doesn't fix, which is simply the group posts URL is not
        // returned when we create the post, and when the post endpoint is queried
        // it returns a URL containing an incorrect domain: api.linkedin.com
        // instead of www.linkedin.com. They acknowledge this in the "Known Issues"
        // section of the groups API documentation and say the workaround is simple:
        // just swap the domains. Well, thanks for nothing. Would it be so hard for
        // them to return a public URL along with the response of the creation?...
        // So we need to make another API call to fetch the correct URL, because
        // it's not even possible to generate it manually.

        $location = $result->getHeader('location');
        $url = $location.':(id,site-group-post-url)?format=json&oauth2_access_token='.$token;
        $result = $client->get($url);
        $json = $result->json();

        $post_url = str_replace('api.linkedin.com/v1', 'www.linkedin.com', $json['siteGroupPostUrl']);
        $response->setPostUrl($post_url);

        return $response;
    }


    public function getProfile()
    {
        $path = '/people/~:(id,first-name,last-name,maiden-name,public-profile-url,formatted-name,num-connections,email-address,num-recommenders)?format=json';
        $response = $this->service->request($path);
        $profile_json = json_decode($response, true);

        $mapping = [
            'id' => 'id',
            'email' => 'emailAddress',
            'name' => 'formattedName',
            'first_name' => 'firstName',
            'middle_name' => 'maidenName',
            'last_name' => 'lastName',
            // 'username' => 'username',
            'link' => 'publicProfileUrl'
        ];

        $profile = Profile::create($mapping, $profile_json);
        $profile->provider = static::$provider;
        $profile->raw_response = $response;

        return $profile;
    }

    public function getStats()
    {
        $path = 'groups/'.$this->id.':(id,num-members)?format=json';
        $response = json_decode($this->request($path));

        return $response->numMembers;
    }
}
