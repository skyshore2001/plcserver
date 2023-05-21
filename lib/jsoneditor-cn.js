JSONEditor.defaults.languages.cn = {
  /**
   * When a property is not set
   */
  error_notset: '字段必须设置值',
  /**
 * When a string must not be empty
 */
  error_notempty: '字段不可为空',
  /**
 * When a value is not one of the enumerated values
 */
  error_enum: '字段值不在选项范围内',
  /**
 * When a value doesn't validate any schema of a 'anyOf' combination
 */
  error_anyOf: '数据格式错误',
  /**
 * When a value doesn't validate
 * @variables This key takes one variable: The number of schemas the value does not validate
 */
  error_oneOf: '数据格式错误，应符合定义 {{0}}',
  /**
 * When a value does not validate a 'not' schema
 */
  error_not: '数据格式错误',
  /**
 * When a value does not match any of the provided types
 */
  error_type_union: '数据类型错误',
  /**
 * When a value does not match the given type
 * @variables This key takes one variable: The type the value should be of
 */
  error_type: '数据类型必须为{{0}}',
  /**
 *  When the value validates one of the disallowed types
 */
  error_disallow_union: '数据类型错误',
  /**
 *  When the value validates a disallowed type
 * @variables This key takes one variable: The type the value should not be of
 */
  error_disallow: '数据类型不允许为{{0}}',
  /**
 * When a value is not a multiple of or divisible by a given number
 * @variables This key takes one variable: The number mentioned above
 */
  error_multipleOf: '值必须是{{0}}的倍数',
  /**
 * When a value is greater than it's supposed to be (exclusive)
 * @variables This key takes one variable: The maximum
 */
  error_maximum_excl: '要求: 值<{{0}}',
  /**
 * When a value is greater than it's supposed to be (inclusive
 * @variables This key takes one variable: The maximum
 */
  error_maximum_incl: '要求: 值<={{0}}',
  /**
 * When a value is lesser than it's supposed to be (exclusive)
 * @variables This key takes one variable: The minimum
 */
  error_minimum_excl: '要求: 值>{{0}}',
  /**
 * When a value is lesser than it's supposed to be (inclusive)
 * @variables This key takes one variable: The minimum
 */
  error_minimum_incl: '要求: 值>={{0}}',
  /**
 * When a value have too many characters
 * @variables This key takes one variable: The maximum character count
 */
  error_maxLength: '要求: 最大长度为{{0}}',
  /**
 * When a value does not have enough characters
 * @variables This key takes one variable: The minimum character count
 */
  error_minLength: '要求: 最小长度为{{0}}',
  /**
 * When a value does not match a given pattern
 */
  error_pattern: '要求: 应匹配模式 {{0}}',
  /**
 * When an array has additional items whereas it is not supposed to
 */
  error_additionalItems: 'No additional items allowed in this array',
  /**
 * When there are to many items in an array
 * @variables This key takes one variable: The maximum item count
 */
  error_maxItems: '要求: 不超过{{0}}项',
  /**
 * When there are not enough items in an array
 * @variables This key takes one variable: The minimum item count
 */
  error_minItems: '要求: 不少于{{0}}项',
  /**
 * When an array is supposed to have unique items but has duplicates
 */
  error_uniqueItems: '不允许有重复项',
  /**
 * When there are too many properties in an object
 * @variables This key takes one variable: The maximum property count
 */
  error_maxProperties: '要求: 不超过{{0}}项',
  /**
 * When there are not enough properties in an object
 * @variables This key takes one variable: The minimum property count
 */
  error_minProperties: '要求: 不少于{{0}}项',
  /**
 * When a required property is not defined
 * @variables This key takes one variable: The name of the missing property
 */
  error_required: "字段'{{0}}'未指定值",
  /**
 * When there is an additional property is set whereas there should be none
 * @variables This key takes one variable: The name of the additional property
 */
  error_additional_properties: '非法字段: {{0}}',
  /**
 * When a dependency is not resolved
 * @variables This key takes one variable: The name of the missing property for the dependency
 */
  error_dependency: '缺少字段: {{0}}',
  /**
 * When a date is in incorrect format
 * @variables This key takes one variable: The valid format
 */
  error_date: '日期格式错误, 要求: {{0}}',
  /**
 * When a time is in incorrect format
 * @variables This key takes one variable: The valid format
 */
  error_time: '时间格式错误, 要求: {{0}}',
  /**
 * When a datetime-local is in incorrect format
 * @variables This key takes one variable: The valid format
 */
  error_datetime_local: '日期时间格式错误, 要求: {{0}}',
  /**
 * When a integer date is less than 1 January 1970
 */
  error_invalid_epoch: '日期不可早于1970-1-1',
  /**
 * When an IPv4 is in incorrect format
 */
  error_ipv4: 'IP地址格式错误, 示例: 1.1.1.1',
  /**
 * When an IPv6 is in incorrect format
 */
  error_ipv6: 'IPv6地址格式错误',
  /**
 * When a hostname is in incorrect format
 */
  error_hostname: '主机名格式错误',
  /**
 * Text on Delete All buttons
 */
  button_delete_all: '全部',
  /**
 * Title on Delete All buttons
 */
  button_delete_all_title: '删除全部',
  /**
  * Text on Delete Last buttons
  * @variable This key takes one variable: The title of object to delete
  */
  button_delete_last: '最后{{0}}',
  /**
  * Title on Delete Last buttons
  * @variable This key takes one variable: The title of object to delete
  */
  button_delete_last_title: '删除最后{{0}}',
  /**
  * Title on Add Row buttons
  * @variable This key takes one variable: The title of object to add
  */
  button_add_row_title: '添加{{0}}',
  /**
  * Title on Move Down buttons
  */
  button_move_down_title: '下移',
  /**
  * Title on Move Up buttons
  */
  button_move_up_title: '上移',
  /**
  * Title on Object Properties buttons
  */
  button_object_properties: '属性',
  /**
  * Title on Delete Row buttons
  * @variable This key takes one variable: The title of object to delete
  */
  button_delete_row_title: '删除 {{0}}',
  /**
  * Title on Delete Row buttons, short version (no parameter with the object title)
  */
  button_delete_row_title_short: '删除',
  /**
  * Title on Copy Row buttons, short version (no parameter with the object title)
  */
  button_copy_row_title_short: '复制',
  /**
  * Title on Collapse buttons
  */
  button_collapse: '折叠',
  /**
  * Title on Expand buttons
  */
  button_expand: '展开',
  /**
  * Title on Flatpickr toggle buttons
  */
  flatpickr_toggle_button: 'Toggle',
  /**
  * Title on Flatpickr clear buttons
  */
  flatpickr_clear_button: 'Clear',
  /**
  * Choices input field placeholder text
  */
  choices_placeholder_text: 'Start typing to add value',
  /**
  * Default title for array items
  */
  default_array_item_title: 'item',
  /**
  * Warning when deleting a node
  */
  button_delete_node_warning: '确认删除该项?'
}

JSONEditor.defaults.language = "cn";
