<?php
/**
 * Response
 *
 * @uses AppModel
 * @package   CTLT.iPeer
 * @author    Pan Luo <pan.luo@ubc.ca>
 * @copyright 2012 All rights reserved.
 * @license   MIT {@link http://www.opensource.org/licenses/MIT}
 */
class Response extends AppModel
{
    public $name = 'Response';
    public $belongsTo = array('Question');

    // Function is Obsolete
    // saves all the responses in the database
    /*function linkResponses($question_id, $data)
{
        for ($i = 1; $i <= $data['Question']['count']; $i++) {
            if (!empty($data['Question']['response_'.$i])) {
                $tmpData['response'] = $data['Question']['response_'.$i];
                $tmpData['question_id'] = $question_id;
                $this->save($tmpData);
                $this->id=null;
            }
        }
    }*/

    /**
     * fillResponse
     * prepares the data public with all the response info from the form for display
     *
     * @param mixed $data
     *
     * @access public
     * @return void
     */
    function fillResponse($data)
    {
        for ($i=0; $i<$data['count']; $i++) {
            $tmp = $this->find('all', array('conditions' => array('question_id' => $data[$i]['Question']['id']),
                'fields' => array('response', 'id')));
            $count = count($tmp);
            for ($j=0; $j<$count; $j++) {
                if (!empty($tmp)) {
                    $data[$i]['Question']['Responses']['response_'.$j]['response'] = $tmp[$j]['Response']['response'];
                    $data[$i]['Question']['Responses']['response_'.$j]['id'] = $tmp[$j]['Response']['id'];
                }
            }
        }
        return $data;
    }


    /**
     * getResponseByQuestionId
     *
     * @param mixed $questionId
     *
     * @access public
     * @return void
     */
    function getResponseByQuestionId($questionId)
    {
        $tmp = $this->find('all', array('conditions' => array('question_id' => $questionId),
            'fields' => array('response', 'id')));
        $data = array();
        for ($j=0; $j< count($tmp); $j++) {
            if (!empty($tmp)) {
                $data['Responses']['response_'.$j]['response'] = $tmp[$j]['Response']['response'];
                $data['Responses']['response_'.$j]['id'] = $tmp[$j]['Response']['id'];
            }
        }
        return $data;
    }


    /**
     * countResponses
     *
     * @param mixed $questionId
     *
     * @access public
     * @return void
     */
    function countResponses($questionId)
    {
        return $this->find('count', array('conditions' => array('question_id' => $questionId)));
    }


    // Function is obsolete
    /*function prepData($data, $questionID)
{
        $tmp = $this->find('all', array('conditions' => array('question_id' => $questionId),
                                    'fields' => array('response')));
        for ($i=0; $i<$data['Question']['count']; $i++ ) {
            $data['Question']['response_'.($i+1)] = $tmp[$i]['Response']['response'];
        }
        return $data;
    }*/

    // Function is obsolete
    /*function getResponseById($id=null)
{
      $tmp = $this->find('first', array('conditions' => array('id' => $id),
                                      'fields' => array('response')));
      return $tmp['Response']['response'];
    }*/

    /**
     * getResponseId
     *
     * @param bool $questionId question id
     * @param bool $response   response
     *
     * @access public
     * @return void
     */
    function getResponseId($questionId=null, $response=null)
    {
        $findResult = $this->find('first', array('conditions'=>array('question_id'=>$questionId, 'response'=>$response)));
        return $findResult['Response']['id'];
    }
}
