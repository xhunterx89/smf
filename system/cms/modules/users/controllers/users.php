<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * User controller for the users module (frontend)
 *
 * @author		 Phil Sturgeon
 * @author		PyroCMS Dev Team
 * @package		PyroCMS\Core\Modules\Users\Controllers
 */
class Users extends Public_Controller
{
	/**
	 * Constructor method
	 *
	 * @return \Users
	 */
	public function __construct()
	{
		parent::__construct();
        $this->load->driver('Streams');
        $this->stream = $this->streams_m->get_stream('profiles', true, 'users');

		// Load the required classes
		$this->load->model('user_m');
		$this->load->model('department/department_m');
		$this->load->model('team/team_m');
		$this->load->model('profile_m');
		$this->load->helper('user');
		$this->lang->load(array('user', 'employee'));
		$this->load->library('form_validation');
	}

	/**
	 * Show the current user's profile
	 */
	public function index()
	{
		if (isset($this->current_user->id))
		{
			$this->view($this->current_user->id);
		}
		else
		{
			redirect('users/login/users');
		}
	}

	/**
	 * View a user profile based on the username
	 *
	 * @param string $username The Username or ID of the user
	 */
	public function view($username = null)
	{
		// work out the visibility setting
		switch (Settings::get('profile_visibility'))
		{
			case 'public':
				// if it's public then we don't care about anything
				break;

			case 'owner':
				// they have to be logged in so we know if they're the owner
				$this->current_user or redirect('users/login/users/view/'.$username);

				// do we have a match?
				$this->current_user->username !== $username and redirect('404');
				break;

			case 'hidden':
				// if it's hidden then nobody gets it
				redirect('404');
				break;

			case 'member':
				// anybody can see it if they're logged in
				$this->current_user or redirect('users/login/users/view/'.$username);
				break;
		}

		// Don't make a 2nd db call if the user profile is the same as the logged in user
		if ($this->current_user && $username === $this->current_user->username)
		{
			$user = $this->current_user;
		}
		// Fine, just grab the user from the DB
		else
		{
			$user = $this->ion_auth->get_user($username);
		}

		// No user? Show a 404 error
		$user or show_404();

		$this->template->build('profile/view', array(
			'_user' => $user,
		));
	}

	/**
	 * Let's login, shall we?
	 */
	public function login()
	{
		// Check post and session for the redirect place
		$redirect_to = ($this->input->post('redirect_to')) 
			? trim(urldecode($this->input->post('redirect_to')))
			: $this->session->userdata('redirect_to');

		// Any idea where we are heading after login?
		if ( ! $_POST and $args = func_get_args())
		{
			$this->session->set_userdata('redirect_to', $redirect_to = implode('/', $args));
		}

		// Get the user data
		$user = (object) array(
			'email' => $this->input->post('email'),
			'password' => $this->input->post('password')
		);

		$validation = array(
			array(
				'field' => 'email',
				'label' => lang('global:email'),
				'rules' => 'required|trim|callback__check_login'
			),
			array(
				'field' => 'password',
				'label' => lang('global:password'),
				'rules' => 'required|min_length['.$this->config->item('min_password_length', 'ion_auth').']|max_length['.$this->config->item('max_password_length', 'ion_auth').']'
			),
		);

		// Set the validation rules
		$this->form_validation->set_rules($validation);

		// If the validation worked, or the user is already logged in
		if ($this->form_validation->run() or $this->current_user)
		{
			// Kill the session
			$this->session->unset_userdata('redirect_to');

			// trigger a post login event for third party devs
			Events::trigger('post_user_login');

			if ($this->input->is_ajax_request())
			{
				$user = $this->ion_auth->get_user_by_email($user->email);
				$user->password = '';
				$user->salt = '';

				exit(json_encode(array('status' => true, 'message' => lang('user:logged_in'), 'data' => $user)));
			}
			else
			{
				$this->session->set_flashdata('success', lang('user:logged_in'));
			}

			// Don't allow protocols or cheeky requests
			if (strpos($redirect_to, ':') !== false and strpos($redirect_to, site_url()) !== 0)
			{
				// Just login to the homepage
				redirect('');
			}

			// Passes muster, on your way
			else
			{
				redirect($redirect_to ? $redirect_to : '');
			}
		}

		if ($_POST and $this->input->is_ajax_request())
		{
			exit(json_encode(array('status' => false, 'message' => validation_errors())));
		}

		$this->template
            ->set_breadcrumb(lang('user:login_header'))
            ->set('breadcrumb_title', $this->module_details['name'])
			->build('login', array(
				'_user' => $user,
				'redirect_to' => $redirect_to,
			));
	}

	/**
	 * Method to log the user out of the system
	 */
	public function logout()
	{
		// allow third party devs to do things right before the user leaves
		Events::trigger('pre_user_logout');

		$this->ion_auth->logout();

		if ($this->input->is_ajax_request())
		{
			exit(json_encode(array('status' => true, 'message' => lang('user:logged_out'))));
		}
		else
		{
			$this->session->set_flashdata('success', lang('user:logged_out'));
			redirect('');
		}
	}

	/**
	 * Method to register a new user
	 */
	public function register()
	{
		if ($this->current_user)
		{
			$this->session->set_flashdata('notice', lang('user:already_logged_in'));
			redirect();
		}

		/* show the disabled registration message */
		if ( ! Settings::get('enable_registration'))
		{
			$this->template
				->title(lang('user:register_title'))
				->build('disabled');
			return;
		}

		// Validation rules
		$validation = array(
			array(
				'field' => 'password',
				'label' => lang('global:password'),
				'rules' => 'required|min_length['.$this->config->item('min_password_length', 'ion_auth').']|max_length['.$this->config->item('max_password_length', 'ion_auth').']'
			),
			array(
				'field' => 'email',
				'label' => lang('global:email'),
				'rules' => 'required|max_length[60]|valid_email|callback__email_check',
			),
			array(
				'field' => 'username',
				'label' => lang('user:username'),
				'rules' => Settings::get('auto_username') ? '' : 'required|alpha_dot_dash|min_length[3]|max_length[20]|callback__username_check',
			),
		);

		// --------------------------------
		// Merge streams and users validation
		// --------------------------------
		// Why are we doing this? We need
		// any fields that are required to
		// be filled out by the user when
		// registering.
		// --------------------------------

		// Get the profile fields validation array from streams
		$this->load->driver('Streams');
		$profile_validation = $this->streams->streams->validation_array('profiles', 'users');

		// Remove display_name
		foreach ($profile_validation as $key => $values)
		{
			if ($values['field'] == 'display_name')
			{
				unset($profile_validation[$key]);
				break;
			}
		}

		// Set the validation rules
		$this->form_validation->set_rules(array_merge($validation, $profile_validation));

		// Get user profile data. This will be passed to our
		// streams insert_entry data in the model.
		$assignments = $this->streams->streams->get_assignments('profiles', 'users');

		// This is the required profile data we have from
		// the register form
		$profile_data = array();

		// Get the profile data to pass to the register function.
		foreach ($assignments as $assign)
		{
			if ($assign->field_slug != 'display_name')
			{
				if (isset($_POST[$assign->field_slug]))
				{
					$profile_data[$assign->field_slug] = $this->input->post($assign->field_slug);
				}
			}
		}

		// --------------------------------

		// Set the validation rules
		$this->form_validation->set_rules($validation);

		$user = new stdClass();

		// Set default values as empty or POST values
		foreach ($validation as $rule)
		{
			$user->{$rule['field']} = $this->input->post($rule['field']) ? $this->input->post($rule['field']) : null;
		}

		// Are they TRYing to submit?
		if ($_POST)
		{
			if ($this->form_validation->run())
			{
				// Check for a bot usin' the old fashioned
				// don't fill this input in trick.
				if ($this->input->post('d0ntf1llth1s1n') !== ' ')
				{
					$this->session->set_flashdata('error', lang('user:register_error'));
					redirect(current_url());
				}

				$email = $this->input->post('email');
				$password = $this->input->post('password');

				// --------------------------------
				// Auto-Username
				// --------------------------------
				// There are no guarantees that we 
				// will have a first/last name to
				// work with, so if we don't, use
				// an alternate method.
				// --------------------------------

				if (Settings::get('auto_username'))
				{
					if ($this->input->post('first_name') and $this->input->post('last_name'))
					{
						$this->load->helper('url');
						$username = url_title($this->input->post('first_name').'.'.$this->input->post('last_name'), '-', true);

						// do they have a long first name + last name combo?
						if (strlen($username) > 19)
						{
							// try only the last name
							$username = url_title($this->input->post('last_name'), '-', true);

							if (strlen($username) > 19)
							{
								// even their last name is over 20 characters, snip it!
								$username = substr($username, 0, 20);
							}
						}
					}
					else
					{
						// If there is no first name/last name combo specified, let's
						// user the identifier string from their email address
						$email_parts = explode('@', $email);
						$username = $email_parts[0];
					}

					// Usernames absolutely need to be unique, so let's keep
					// trying until we get a unique one
					$i = 1;

					$username_base = $username;

					while ($this->db->where('username', $username)
						->count_all_results('users') > 0)
					{
						// make sure that we don't go over our 20 char username even with a 2 digit integer added
						$username = substr($username_base, 0, 18).$i;

						++$i;
					}
				}
				else
				{
					// The user specified a username, so let's use that.
					$username = $this->input->post('username');
				}

				// --------------------------------

				// Do we have a display name? If so, let's use that.
				// Othwerise we can use the username.
				if ( ! isset($profile_data['display_name']) or ! $profile_data['display_name'])
				{
					$profile_data['display_name'] = $username;
				}

				// We are registering with a null group_id so we just
				// use the default user ID in the settings.
				$id = $this->ion_auth->register($username, $password, $email, null, $profile_data);

				// Try to create the user
				if ($id > 0)
				{
					// Convert the array to an object
					$user->username = $username;
					$user->display_name = $username;
					$user->email = $email;
					$user->password = $password;

					// trigger an event for third party devs
					Events::trigger('post_user_register', $id);

					/* send the internal registered email if applicable */
					if (Settings::get('registered_email'))
					{
						$this->load->library('user_agent');

						Events::trigger('email', array(
							'name' => $user->display_name,
							'sender_ip' => $this->input->ip_address(),
							'sender_agent' => $this->agent->browser().' '.$this->agent->version(),
							'sender_os' => $this->agent->platform(),
							'slug' => 'registered',
							'email' => Settings::get('contact_email'),
						), 'array');
					}

					// show the "you need to activate" page while they wait for their email
					if ((int)Settings::get('activation_email') === 1)
					{
						$this->session->set_flashdata('notice', $this->ion_auth->messages());
						redirect('users/activate');
					}
					// activate instantly
					elseif ((int)Settings::get('activation_email') === 2)
					{
						$this->ion_auth->activate($id, false);

						$this->ion_auth->login($this->input->post('email'), $this->input->post('password'));
						redirect($this->config->item('register_redirect', 'ion_auth'));
					}
					else
					{
						$this->ion_auth->deactivate($id);

						/* show that admin needs to activate your account */
						$this->session->set_flashdata('notice', lang('user:activation_by_admin_notice'));
						redirect('users/register'); /* bump it to show the flash data */
					}
				}

				// Can't create the user, show why
				else
				{
					$this->template->error_string = $this->ion_auth->errors();
				}
			}
			else
			{
				// Return the validation error
				$this->template->error_string = $this->form_validation->error_string();
			}
		}

		// Is there a user hash?
		else {
			if (($user_hash = $this->session->userdata('user_hash')))
			{
				// Convert the array to an object
				$user->email = ( ! empty($user_hash['email'])) ? $user_hash['email'] : '';
				$user->username = $user_hash['nickname'];
			}
		}

		// --------------------------------
		// Create profile fields.
		// --------------------------------

		// Anything in the post?

		$this->template->set('profile_fields', $this->streams->fields->get_stream_fields('profiles', 'users', $profile_data));

		// --------------------------------

		$this->template
			->title(lang('user:register_title'))
            ->set_breadcrumb(lang('user:register_header'))
            ->set('breadcrumb_title', $this->module_details['name'])
			->set('_user', $user)
			->build('register');
	}

	// --------------------------------------------------------------------------

	/**
	 * Activate a user
	 *
	 * @param int $id The ID of the user
	 * @param string $code The activation code
	 *
	 * @return void
	 */
	public function activate($id = 0, $code = null)
	{
		// Get info from email
		if ($this->input->post('email'))
		{
			$this->template->activate_user = $this->ion_auth->get_user_by_email($this->input->post('email'));
			$id = $this->template->activate_user->id;
		}

		$code = ($this->input->post('activation_code')) ? $this->input->post('activation_code') : $code;

		// If user has supplied both bits of information
		if ($id and $code)
		{
			// Try to activate this user
			if ($this->ion_auth->activate($id, $code))
			{
				$this->session->set_flashdata('activated_email', $this->ion_auth->messages());

				// trigger an event for third party devs
				Events::trigger('post_user_activation', $id);

				redirect('users/activated');
			}
			else
			{
				$this->template->error_string = $this->ion_auth->errors();
			}
		}

		$this->template
			->title(lang('user:activate_account_title'))
			->set_breadcrumb(lang('user:activate_label'), 'users/activate')
			->build('activate');
	}

	/**
	 * Activated page.
	 *
	 * Shows an activated messages and a login form.
	 */
	public function activated()
	{
		//if they are logged in redirect them to the home page
		if ($this->current_user)
		{
			redirect(base_url());
		}

		$this->template->activated_email = ($email = $this->session->flashdata('activated_email')) ? $email : '';

		$this->template
			->title(lang('user:activated_account_title'))
			->build('activated');
	}

	/**
	 * Reset a user's password
	 *
	 * @param bool $code
	 */
	public function reset_pass($code = null)
	{
		$this->template->title(lang('user:reset_password_title'));

		if (PYRO_DEMO)
		{
			show_error(lang('global:demo_restrictions'));
		}

		//if user is logged in they don't need to be here
		if ($this->current_user)
		{
			$this->session->set_flashdata('error', lang('user:already_logged_in'));
			redirect('');
		}

		if ($this->input->post('email'))
		{
			$uname = (string) $this->input->post('user_name');
			$email = (string) $this->input->post('email');

			if ( ! $uname and ! $email)
			{
				// they submitted with an empty form, abort
				$this->template->set('error_string', $this->ion_auth->errors())
					->build('reset_pass');
			}

			if ( ! ($user_meta = $this->ion_auth->get_user_by_email($email)))
			{
				$user_meta = $this->ion_auth->get_user_by_username($email);
			}

			// have we found a user?
			if ($user_meta)
			{
				$new_password = $this->ion_auth->forgotten_password($user_meta->email);

				if ($new_password)
				{
					//set success message
					$this->template->success_string = lang('forgot_password_successful');
				}
				else
				{
					// Set an error message explaining the reset failed
					$this->template->error_string = $this->ion_auth->errors();
				}
			}
			else
			{
				//wrong username / email combination
				$this->template->error_string = lang('user:forgot_incorrect');
			}
		}

		// code is supplied in url so lets try to reset the password
		if ($code)
		{
			// verify reset_code against code stored in db
			$reset = $this->ion_auth->forgotten_password_complete($code);

			// did the password reset?
			if ($reset)
			{
				redirect('users/reset_complete');
			}
			else
			{
				// nope, set error message
				$this->template->error_string = $this->ion_auth->errors();
			}
		}

		$this->template->build('reset_pass');
	}

	/**
	 * Password reset is finished
	 */
	public function reset_complete()
	{
		PYRO_DEMO and show_error(lang('global:demo_restrictions'));

		//if user is logged in they don't need to be here. and should use profile options
		if ($this->current_user)
		{
			$this->session->set_flashdata('error', lang('user:already_logged_in'));
			redirect('my-profile');
		}

		$this->template
			->title(lang('user:password_reset_title'))
			->build('reset_pass_complete');
	}

	/**
	 * Edit Profile
	 *
	 * @param int $id
	 */
	public function edit($id = 0)
	{
		if ($this->current_user and $this->current_user->group === 'admin' and $id > 0)
		{
			$user = $this->user_m->get(array('id' => $id));

			// invalide user? Show them their own profile
			$user or redirect('edit-profile');
		}
		else
		{
			$user = $this->current_user or redirect('users/login/users/edit'.(($id > 0) ? '/'.$id : ''));
		}

		$profile_data = array(); // For our form

		// Get the profile data
		$profile_row = $this->db->limit(1)
			->where('user_id', $user->id)->get('profiles')->row();

		// If we have API's enabled, load stuff
		if (Settings::get('api_enabled') and Settings::get('api_user_keys'))
		{
			$this->load->model('api/api_key_m');
			$this->load->language('api/api');

			$api_key = $this->api_key_m->get_active_key($user->id);
		}

		$this->validation_rules = array(
			array(
				'field' => 'email',
				'label' => lang('user:email'),
				'rules' => 'required|xss_clean|valid_email'
			),
			array(
				'field' => 'display_name',
				'label' => lang('profile_display_name'),
				'rules' => 'required|xss_clean'
			)
		);

		// --------------------------------
		// Merge streams and users validation
		// --------------------------------

		// Get the profile fields validation array from streams
		$this->load->driver('Streams');
		$profile_validation = $this->streams->streams->validation_array('profiles', 'users', 'edit', array(), $profile_row->id);

		// Set the validation rules
		$this->form_validation->set_rules(array_merge($this->validation_rules, $profile_validation));

		// Get user profile data. This will be passed to our
		// streams insert_entry data in the model.
		$assignments = $this->streams->streams->get_assignments('profiles', 'users');

		// --------------------------------

		// Settings valid?
		if ($this->form_validation->run())
		{
			PYRO_DEMO and show_error(lang('global:demo_restrictions'));

			// Get our secure post
			$secure_post = $this->input->post();

			$user_data = array(); // Data for our user table
			$profile_data = array(); // Data for our profile table

			// --------------------------------
			// Deal with non-profile fields
			// --------------------------------
			// The non-profile fields are:
			// - email
			// - password
			// The rest are streams
			// --------------------------------

			$user_data['email'] = $secure_post['email'];

			// If password is being changed (and matches)
			if ($secure_post['password'])
			{
				$user_data['password'] = $secure_post['password'];
				unset($secure_post['password']);
			}

			// --------------------------------
			// Set the language for this user
			// --------------------------------

			if (isset($secure_post['lang']) and $secure_post['lang'])
			{
				$this->ion_auth->set_lang($secure_post['lang']);
				$_SESSION['lang_code'] = $secure_post['lang'];
			}

			// --------------------------------
			// The profile data is what is left
			// over from secure_post.
			// --------------------------------

			$profile_data = $secure_post;

			if ($this->ion_auth->update_user($user->id, $user_data, $profile_data) !== false)
			{
				Events::trigger('post_user_update');
				$this->session->set_flashdata('success', $this->ion_auth->messages());
			}
			else
			{
				$this->session->set_flashdata('error', $this->ion_auth->errors());
			}

			redirect('users/edit'.(($id > 0) ? '/'.$id : ''));
		}
		else
		{
			// --------------------------------
			// Grab user data
			// --------------------------------
			// Currently just the email.
			// --------------------------------		

			if (isset($_POST['email']))
			{
				$user->email = $_POST['email'];
			}
		}

		// --------------------------------
		// Grab user profile data
		// --------------------------------

		foreach ($assignments as $assign)
		{
			if (isset($_POST[$assign->field_slug]))
			{
				$profile_data[$assign->field_slug] = $this->input->post($assign->field_slug);
			}
			else
			{
				$profile_data[$assign->field_slug] = $profile_row->{$assign->field_slug};
			}
		}

		// --------------------------------
		// Run Stream Events
		// --------------------------------

		$profile_stream_id = $this->streams_m->get_stream_id_from_slug('profiles', 'users');
		$this->fields->run_field_events($this->streams_m->get_stream_fields($profile_stream_id), array());

		// --------------------------------

		// Render the view
		$this->template->build('profile/edit', array(
			'_user' => $user,
			'display_name' => $profile_row->display_name,
			'profile_fields' => $this->streams->fields->get_stream_fields('profiles', 'users', $profile_data),
			'api_key' => isset($api_key) ? $api_key : null,
		));
	}

	/**
	 * Callback method used during login
	 *
	 * @param str $email The Email address
	 *
	 * @return bool
	 */
	public function _check_login($email)
	{
		$remember = false;
		if ($this->input->post('remember') == 1)
		{
			$remember = true;
		}

		if ($this->ion_auth->login($email, $this->input->post('password'), $remember))
		{
			return true;
		}

		Events::trigger('login_failed', $email);
		error_log('Login failed for user '.$email);

		$this->form_validation->set_message('_check_login', $this->ion_auth->errors());
		return false;
	}

	/**
	 * Username check
	 *
	 * @author Ben Edmunds
	 *
	 * @param string $username The username to check.
	 *
	 * @return bool
	 */
	public function _username_check($username)
	{
		if ($this->ion_auth->username_check($username))
		{
			$this->form_validation->set_message('_username_check', lang('user:error_username'));
			return false;
		}

		return true;
	}

	/**
	 * Email check
	 *
	 * @author Ben Edmunds
	 *
	 * @param string $email The email to check.
	 *
	 * @return bool
	 */
	public function _email_check($email)
	{
		if ($this->ion_auth->email_check($email))
		{
			$this->form_validation->set_message('_email_check', lang('user:error_email'));
			return false;
		}

		return true;
	}

    // --------------------------------------------------------------------------

    /**
     * Method to show employee list
     */
    public function employee(){
        if(!check_user_permission($this->current_user, $this->module, $this->permissions)) redirect();
        $where = "";
        if ($this->input->post('f_keywords')) {
            $where .= "`first_name` LIKE '%".$this->input->post('f_keywords')."%' ";
        }

        // Get the latest team posts
        $posts = $this->streams->entries->get_entries(array(
            'stream'		=> 'profiles',
            'namespace'		=> 'users',
            'limit'         => Settings::get('records_per_page'),
            'where'		    => $where,
            'paginate'		=> 'yes',
            'pag_base'		=> site_url('users/employee/page'),
            'pag_segment'   => 3
        ));

        // Process posts
        foreach ($posts['entries'] as &$post) {
            $this->_process_post($post);
        }
        $this->template->set_layout('feedback_layout.html');
        $this->input->is_ajax_request() and $this->template->set_layout(false);

        if(!$this->input->is_ajax_request()){
            $department = array(''  => lang('employee:select_department'));
            $departments = $this->streams->entries->get_entries(array('stream' => 'department', 'namespace' => 'departments'));
            foreach ($departments['entries'] as $post) {
                $department[$post['id']] = $post['title'];
            }
            $this->template->set('departments', $department);

            $team = array(''  => lang('employee:select_team'));
            $teams = $this->streams->entries->get_entries(array('stream' => 'team', 'namespace' => 'teams'));
            foreach ($teams['entries'] as $post) {
                $team[$post['id']] = $post['title'];
            }
            $this->template->set('teams', $team);
        }

        $this->template
            ->title(lang('employee:title'))
            ->set_breadcrumb(lang('employee:employee_title'))
            ->set('breadcrumb_title', lang('employee:title'))
            ->set_metadata('og:title', lang('employee:title'), 'og')
            ->set_metadata('og:type', 'employee', 'og')
            ->set_metadata('og:url', current_url(), 'og')
            ->append_js('module::employee_form.js')
            ->set_stream($this->stream->stream_slug, $this->stream->stream_namespace)
            ->set('posts', $posts['entries'])
            ->set('pagination', $posts['pagination']);

        $this->input->is_ajax_request()
            ? $this->template->build('tables/posts')
            : $this->template->build('index');
    }

    /**
     * The process function
     * @Description: This is process function
     * @Parameter:
     * @Return: null
     * @Date: 11/21/13
     * @Update: 11/21/13
     */
    public function employee_process(){
        if(!$this->input->is_ajax_request()) redirect('team');
        if($this->input->post('action') == 'create'){
            $message = $this->employee_create();
            echo json_encode($message);
        }else if($this->input->post('action') == 'edit'){
            $message = $this->employee_edit();
            echo json_encode($message);
        }
    }

    /**
     * Method to employee_create a new user
     */
    private function employee_create()
    {
        $message = array();
        if (!$this->current_user)
        {
            $message['status']  = 'error';
            $message['message']  = lang('employee:activation_not_allow');
            return $message;
        }
        // Validation rules
        $validation = array(
            array(
                'field' => 'email',
                'label' => lang('global:email'),
                'rules' => 'required|max_length[60]|valid_email|callback__email_check',
            ),
            array(
                'field' => 'username',
                'label' => lang('user:username'),
                'rules' => Settings::get('auto_username') ? '' : 'required|alpha_dot_dash|min_length[3]|max_length[20]|callback__username_check',
            ),
            array(
                'field' => 'department_id',
                'label' => lang('employee:form_department'),
                'rules' => 'required|numeric',
            ),
            array(
                'field' => 'team_id',
                'label' => lang('employee:form_team'),
                'rules' => 'required|numeric',
            ),
        );

        // --------------------------------
        // Merge streams and users validation
        // --------------------------------
        // Why are we doing this? We need
        // any fields that are required to
        // be filled out by the user when
        // registering.
        // --------------------------------

        // Get the profile fields validation array from streams
        $this->load->driver('Streams');
        $profile_validation = $this->streams->streams->validation_array('profiles', 'users');

        // Remove display_name
        foreach ($profile_validation as $key => $values)
        {
            if ($values['field'] == 'display_name')
            {
                unset($profile_validation[$key]);
                break;
            }
        }

        // Set the validation rules
        $this->form_validation->set_rules(array_merge($validation, $profile_validation));

        // Get user profile data. This will be passed to our
        // streams insert_entry data in the model.
        $assignments = $this->streams->streams->get_assignments('profiles', 'users');

        // This is the required profile data we have from
        // the register form
        $profile_data = array();

        // Get the profile data to pass to the register function.
        foreach ($assignments as $assign)
        {
            if ($assign->field_slug != 'display_name')
            {
                if (isset($_POST[$assign->field_slug]))
                {
                    $profile_data[$assign->field_slug] = $this->input->post($assign->field_slug);
                }
            }
        }

        // --------------------------------

        // Set the validation rules
        $this->form_validation->set_rules($validation);
        $user = new stdClass();

        // Set default values as empty or POST values
        foreach ($validation as $rule)
        {
            $user->{$rule['field']} = $this->input->post($rule['field']) ? $this->input->post($rule['field']) : null;
        }

        // Are they TRYing to submit?
        if ($_POST)
        {
            if ($this->form_validation->run())
            {
                // Check for a bot usin' the old fashioned
                // don't fill this input in trick.
                if ($this->input->post('d0ntf1llth1s1n') !== ' ')
                {
                    $message['status']  = 'error';
                    $message['message']  = lang('user:register_error');
                    return $message;
                }

                $email = $this->input->post('email');
                //$password = $this->input->post('password');
                $password = rand(111111, 9999999999);
                $department = $this->input->post('department_id');
                $team = $this->input->post('team_id');

                // --------------------------------
                // Auto-Username
                // --------------------------------
                // There are no guarantees that we
                // will have a first/last name to
                // work with, so if we don't, use
                // an alternate method.
                // --------------------------------

                if (Settings::get('auto_username'))
                {
                    if ($this->input->post('first_name') and $this->input->post('last_name'))
                    {
                        $this->load->helper('url');
                        $username = url_title($this->input->post('first_name').'.'.$this->input->post('last_name'), '-', true);

                        // do they have a long first name + last name combo?
                        if (strlen($username) > 19)
                        {
                            // try only the last name
                            $username = url_title($this->input->post('last_name'), '-', true);

                            if (strlen($username) > 19)
                            {
                                // even their last name is over 20 characters, snip it!
                                $username = substr($username, 0, 20);
                            }
                        }
                    }
                    else
                    {
                        // If there is no first name/last name combo specified, let's
                        // user the identifier string from their email address
                        $email_parts = explode('@', $email);
                        $username = $email_parts[0];
                    }

                    // Usernames absolutely need to be unique, so let's keep
                    // trying until we get a unique one
                    $i = 1;

                    $username_base = $username;

                    while ($this->db->where('username', $username)
                            ->count_all_results('users') > 0)
                    {
                        // make sure that we don't go over our 20 char username even with a 2 digit integer added
                        $username = substr($username_base, 0, 18).$i;

                        ++$i;
                    }
                }
                else
                {
                    // The user specified a username, so let's use that.
                    $username = $this->input->post('username');
                }

                // --------------------------------

                // Do we have a display name? If so, let's use that.
                // Othwerise we can use the username.
                if ( ! isset($profile_data['display_name']) or ! $profile_data['display_name'])
                {
                    $profile_data['display_name'] = $username;
                }

                // We are registering with a null group_id so we just
                // use the default user ID in the settings.
                $id = $this->ion_auth->register($username, $password, $email, $department, $team, null, $profile_data);

                // Try to create the user
                if ($id > 0)
                {
                    // Convert the array to an object
                    $user->username = $username;
                    $user->display_name = $username;
                    $user->email = $email;
                    $user->password = $password;

                    // trigger an event for third party devs
                    Events::trigger('post_user_register', $id);

                    /* send the internal registered email if applicable */
                    if (Settings::get('registered_email'))
                    {
                        $this->load->library('user_agent');
                        Events::trigger('email', array(
                            'name' => $user->display_name,
                            'sender_ip' => $this->input->ip_address(),
                            'sender_agent' => $this->agent->browser().' '.$this->agent->version(),
                            'sender_os' => $this->agent->platform(),
                            'slug' => 'registered',
                            'email' => Settings::get('contact_email'),
                        ), 'array');
                    }

                    // show the "you need to activate" page while they wait for their email
                    if ((int)Settings::get('activation_email') === 1)
                    {
                        $message['status']  = 'success';
                        $message['message']  = lang('employee:created_success_email_send');
                        return $message;
                    }
                    // activate instantly
                    elseif ((int)Settings::get('activation_email') === 2)
                    {
                        $this->ion_auth->activate($id, false);
                        $this->ion_auth->login($this->input->post('email'), $this->input->post('password'));
                        $message['status']  = 'success';
                        $message['message']  = lang('employee:created_success');
                        return $message;
                    }
                    else
                    {
                        $this->ion_auth->deactivate($id);
                        /* show that admin needs to activate your account */
                        $message['status']  = 'warning';
                        $message['message']  = lang('user:activation_by_admin_notice');
                        return $message;
                    }
                }

                // Can't create the user, show why
                else
                {
                    $message['status']  = 'error';
                    $message['message']  = $this->ion_auth->errors();
                    return $message;
                }
            }
            else
            {
                // Return the validation error
                $message['status']  = 'error';
                $message['message']  = $this->form_validation->error_string();
                return $message;
            }
        }
        // Is there a user hash?
        else {
            if (($user_hash = $this->session->userdata('user_hash')))
            {
                // Convert the array to an object
                $user->email = ( ! empty($user_hash['email'])) ? $user_hash['email'] : '';
                $user->username = $user_hash['nickname'];
            }
        }

    }

    /**
     * Method to employee_edit a user
     */
    private function employee_edit(){
        $message = array();
        $message['status']  = 'error';
        $message['message']  = 'Not allow!';
        return $message;
    }

    /**
     * The process_post function
     * @Description: This is process_post function
     * @Parameter:
     * @Return: null
     * @Date: 11/21/13
     * @Update: 11/21/13
     */
    private function _process_post(&$post) {
        $post['department'] = get_department_by_user_id($post['user_id']);
    }

    public function get_employee_by_id($id)
    {
    	$profile = $this->profile_m->get_by(array('id'=>$id));
    	$user_id = $profile->user_id;
    	if (!$this->input->is_ajax_request())
            redirect('employee');
        if ($user_id != null && $user_id != "") {
            $item = $this->user_m->get_by(array('id' =>$user_id));
            
            $item->department = $this->department_m->get_by(array('id'=>$item->department_id))->title;
            $item->team = $this->team_m->get_by(array('id'=>$item->team_id))->title;
            echo json_encode($item);
        } else {
            echo "";
        }
    }

    function get_department($id)
    {
    	$item = $this->department_m->get_by(array('id'=>$id));
    	print_r($item);
    }


        /**
     * The delete function
     * @Description: This is delete function
     * @Parameter:
     *      1. $id int The ID of the feedback manager post to delete
     * @Return: null
     * @Date: 11/21/13
     * @Update: 11/21/13
     */
    public function delete($id = 0) {
        $ids = ($id) ? array($id) : $this->input->post('action_to');
        if (!empty($ids)) {
            $post_names = array();
            $deleted_ids = array();

            foreach ($ids as $id){
                if ($post = $this->profile_m->get_by(array('id'=>$id))){
                    if ($this->profile_m->delete($id)){
                    	$id_user = $this->user_m->get_by(array('id'=>$post->user_id))->id;
                    	$this->user_m->delete($id_user);
                        $post_names[] = $post->first_name;
                        $deleted_ids[] = $id;
                    }
                }
            }
            Events::trigger('employee_deleted', $deleted_ids);
        }
        $message = array();
        if (!empty($post_names)) {
            if (count($post_names) == 1) {
                $message['status'] = 'success';
                $message['message'] = str_replace("%s", $post_names[0], lang('employee:delete_success'));
            } else {
                $message['status'] = 'success';
                $message['message'] = str_replace("%s", implode('", "', $post_names), lang('employee:mass_delete_success'));
            }
        } else {
            $message['status'] = 'warning';
            $message['message'] = lang('employee:delete_error');
        }
        echo json_encode($message);
    }

    // public function get_profile($id)
    // {
    // 	$id_profile = $this->profile_m->get_by(array('user_id'=>$id))->id;
    // 	echo $id_profile;
    // }
    /**
     * The action function
     * @Description: This is action function
     * @Parameter:
     * @Return: null
     * @Date: 11/21/13
     * @Update: 11/21/13
     */
    public function action() {
        switch ($this->input->post('btnAction')) {
            case 'delete':
                $this->delete();
                break;
            default:
                echo '';
                break;
        }
    }

}