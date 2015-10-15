<?php

namespace Codebender\BuilderBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * default controller of api bundle
 */
class DefaultController extends Controller
{
    /**
     * status action
     *
     * @return Response Response instance.
     *
     */
    public function statusAction()
    {
        return new Response(json_encode(array("success" => true, "status" => "OK")));
    }

    /**
     * Gets a request for compilation or library fetching (depends on the 'type' field of the request)
     * and passes the request to either the compiler or the library manager.
     *
     * Includes several checks in order to ensure the validity of the data provided as well
     * as authentication.
     *
     * @param $authKey
     * @param $version
     * @return Response
     */
    public function handleRequestAction($authKey, $version)
    {
        if ($authKey !== $this->container->getParameter('authorizationKey')) {
            return new Response(json_encode(["success" => false, "message" => "Invalid authorization key."]));
        }

        if ($version !== $this->container->getParameter('version')) {
            return new Response(json_encode(["success" => false, "message" => "Invalid api version."]));
        }

        $request = $this->getRequest()->getContent();
        if (empty($request)) {
            return new Response(json_encode(["success" => false, "message" => "Invalid input."]));
        }

        $contents = json_decode($request, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new Response(json_encode(["success" => false, "message" => "Wrong data."]));
        }

        if (!array_key_exists("data", $contents)) {
            return new Response(json_encode(["success" => false, "message" => "Insufficient data provided."]));
        }

        if ($contents["type"] == "compiler") {
            return new Response($this->compile($contents["data"]));
        }

        if ($contents["type"] == "library") {
            return new Response($this->getLibraryInfo(json_encode($contents["data"])));
        }

        return new Response(
            json_encode(
                [
                    "success" => false,
                    "message" => "Invalid request type (can handle only 'compiler' or 'library' requests)"
                ]
            ));
    }

    /**
     * Gets the data from the handleRequestAction and proceeds with the compilation
     *
     * @param $contents
     * @return Response
     *
     * @SuppressWarnings(PHPMD.LongVariable)
     */
    protected function compile($contents)
    {
        $apiHandler = $this->get('codebender_builder.handler');

        $contents = $this->checkForUserIdProjectId($contents);

        $files = $contents["files"];

        $userLibraries = [];

        if (array_key_exists('libraries', $contents)) {
            $userLibraries = $contents['libraries'];
        }

        $userAndLibmanLibraries = $this->returnProvidedAndFetchedLibraries($files, $userLibraries);

        $contents["libraries"] = $userAndLibmanLibraries['libraries'];

        $compilerRequestContent = json_encode($contents);

        // perform the actual post to the compiler
        $data = $apiHandler->postRawData($this->container->getParameter('compiler'), $compilerRequestContent);

        $decodedResponse = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return json_encode(["success" => false, "message"=> "Failed to get compiler response."]);
        }

        if ($decodedResponse["success"] === false && !array_key_exists("step", $decodedResponse)) {
            $decodedResponse["step"] = "unknown";
        }

        unset($userAndLibmanLibraries['libraries']);
        $decodedResponse['additionalCode'] = $userAndLibmanLibraries;

        return json_encode($decodedResponse);
    }

    /**
     * Gets a request for library information from the handleRequestAction and makes the
     * actual call to the library manager.
     * The data must be already json encoded before passing them to this function.
     *
     * @param $data
     * @return mixed
     */
    protected function getLibraryInfo($data)
    {
        $handler = $this->get('codebender_builder.handler');

        $libraryManager = $this->container->getParameter('library');

        return $handler->postRawData($libraryManager, $data);
    }

    /**
     *
     * @param array $projectFiles
     * @param array $userLibraries
     *
     * @return array
     */
    protected function returnProvidedAndFetchedLibraries($projectFiles, $userLibraries)
    {
        $apiHandler = $this->get('codebender_builder.handler');

        $detectedHeaders = $apiHandler->readLibraries($projectFiles);

        // declare arrays
        $notFoundHeaders = [];
        $foundHeaders = [];
        $librariesFromLibman = [];
        $providedLibraries = array_keys($userLibraries);
        $libraries = $userLibraries;

        foreach ($detectedHeaders as $header) {

            $existsInRequest = false;
            // TODO We can do this in a better way
            foreach ($userLibraries as $library) {
                foreach ($library as $libraryContent) {
                    if ($libraryContent["filename"] == $header.".h") {
                        $existsInRequest = true;
                        $foundHeaders[] = $header . ".h";
                    }
                }
            }

            if ($existsInRequest === true) {
                continue;
            }
            $requestContent = ["type" => "fetch", "library" => $header];
            $data = $this->getLibraryInfo(json_encode($requestContent));
            $data = json_decode($data, true);

            if ($data['success'] === false) {
                $notFoundHeaders[] = $header . ".h";
                continue;
            }

            $foundHeaders[] = $header . ".h";
            $librariesFromLibman[] = $header;
            $filesToBeAdded = [];
            foreach ($data["files"] as $file) {
                if (in_array(pathinfo($file['filename'], PATHINFO_EXTENSION), array('cpp', 'h', 'c', 'S', 'inc')))
                    $filesToBeAdded[] = $file;
            }
            $libraries[$header] = $filesToBeAdded;
        }

        return [
            'libraries' => $libraries,
            'providedLibraries' => $providedLibraries,
            'fetchedLibraries' => $librariesFromLibman,
            'detectedHeaders'=> $detectedHeaders,
            'foundHeaders' => $foundHeaders,
            'notFoundHeaders' => $notFoundHeaders
        ];
    }

    /**
      * Checks if project id and user id exist in the request.
      * If not, adds the fields with null id
      *
      * @param array $requestContents
      * @return array
      */
    protected function checkForUserIdProjectId($requestContents)
    {
        if (!array_key_exists('userId', $requestContents)) {
            $requestContents['userId'] = 'null';
        }

        if (!array_key_exists('projectId', $requestContents)) {
            $requestContents['projectId'] = 'null';
        }

        return $requestContents;
    }
}

